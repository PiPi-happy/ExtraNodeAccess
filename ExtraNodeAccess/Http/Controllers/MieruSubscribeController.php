<?php

namespace Plugin\ExtraNodeAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * 加速订阅端点（v2.5）。
 *
 * 面向 OpenClash 等无「扩展脚本」能力的 Clash 客户端：客户端把订阅地址指向本端点，
 * 服务端实时拉取该用户的 subconverter 转换结果，注入 mieru 链式加速结构
 * （落地节点 provider 拆分 + dialer-proxy override），返回可直接运行的完整配置。
 * 客户端照常轮询订阅即可，注入每次自动生效，无需客户端做任何额外配置。
 *
 * 路由挂在 /api/v2/ena-sub/v1/* 下，不走 admin secure_path：
 * 用「用户订阅 token」鉴权（与面板订阅链接 /s/{token} 同源凭据）。
 *
 * 行为参数（后台插件设置可改）：
 * - clash_sub_enabled          总开关
 * - clash_sub_subconverter     subconverter 地址模板（含 target=clash&url=）
 * - clash_sub_landing_keywords 落地节点关键字（逗号分隔）
 * - clash_sub_relay_keywords   mieru 中转节点关键字（逗号分隔）
 * - clash_sub_group_name       前置代理组名
 * - clash_sub_prefix           加速版节点名前缀
 */
class MieruSubscribeController extends Controller
{
    /** GET /clash?token= — 输出注入加速结构后的 Clash 配置 */
    public function clash(Request $request)
    {
        if (!admin_setting('clash_sub_enabled', true)) {
            return $this->raw('加速订阅端点未启用', 403);
        }

        $token = (string) $request->input('token', '');
        if ($token === '' || !preg_match('/^[A-Za-z0-9]{8,64}$/', $token)) {
            return $this->raw('无效的订阅 token', 403);
        }

        $user = User::where('token', $token)->first();
        if (!$user) {
            return $this->raw('无效的订阅 token', 403);
        }

        // 结果缓存：同一 token + 同一组注入参数 1 小时内复用（处理本身毫秒级，
        // 缓存主要降低 subconverter 链路被频繁触发的概率；客户端照常按自己的节奏更新订阅）
        $cacheKey = 'ena_clash_sub:' . md5(implode('|', [
            $token,
            (string) admin_setting('clash_sub_subconverter', ''),
            (string) admin_setting('clash_sub_landing_keywords'),
            (string) admin_setting('clash_sub_relay_keywords'),
            (string) admin_setting('clash_sub_group_name'),
            (string) admin_setting('clash_sub_prefix'),
        ]));

        // 仅缓存成功注入的结果；透传（raw）不缓存，配置修正后下一次请求立即生效
        $result = Cache::get($cacheKey);
        if ($result === null) {
            $result = $this->build($token, $request);
            if (empty($result['raw'])) {
                Cache::put($cacheKey, $result, 600);
            }
        }

        $headers = [
            'Content-Type' => 'application/octet-stream; charset=utf-8',
            'subscription-userinfo' => sprintf(
                'upload=%d; download=%d; total=%d; expire=%d',
                (int) $user->u,
                (int) $user->d,
                (int) $user->transfer_enable,
                (int) $user->expired_at
            ),
            'profile-update-interval' => '24',
        ];

        if (!empty($result['raw'])) {
            // subconverter 拉取/解析失败：透传原始输出，保证订阅不中断（只是暂无加速）
            Log::warning('ena clash sub: 注入失败，已透传原始订阅', ['err' => $result['err'] ?? '']);
            return response($result['yaml'], 200, $headers);
        }

        return response($result['yaml'], 200, $headers);
    }

    /**
     * 拉取 subconverter 转换结果并注入加速结构。
     * 返回 ['yaml' => string, 'raw' => bool, 'err' => string]。
     */
    private function build(string $token, Request $request): array
    {
        $base = rtrim((string) admin_setting('clash_sub_subconverter', ''));
        if ($base === '') {
            return ['yaml' => '加速订阅端点未配置 subconverter 地址', 'raw' => true, 'err' => 'subconverter 未配置'];
        }

        $subUrl = $base . (str_contains($base, 'url=') ? '' : 'url=')
            . urlencode($request->getSchemeAndHttpHost() . '/s/' . $token);

        try {
            $resp = Http::timeout(20)->get($subUrl);
        } catch (\Throwable $e) {
            return ['yaml' => '', 'raw' => true, 'err' => 'subconverter 请求异常: ' . $e->getMessage()];
        }
        if (!$resp->successful() || trim($resp->body()) === '') {
            return ['yaml' => (string) $resp->body(), 'raw' => true, 'err' => 'subconverter HTTP ' . $resp->status()];
        }

        $body = $resp->body();
        try {
            $config = Yaml::parse($body);
        } catch (\Throwable $e) {
            return ['yaml' => $body, 'raw' => true, 'err' => 'YAML 解析失败: ' . $e->getMessage()];
        }
        if (!is_array($config)) {
            return ['yaml' => $body, 'raw' => true, 'err' => 'YAML 根节点非数组'];
        }

        $this->inject(
            $config,
            $this->keywords('clash_sub_landing_keywords', '香港BA,ICX,日本GR,日本OR'),
            $this->keywords('clash_sub_relay_keywords', '京渝桂粤加速'),
            (string) admin_setting('clash_sub_group_name', '加速组'),
            (string) admin_setting('clash_sub_prefix', '[加速版]')
        );

        try {
            $out = Yaml::dump($config, 8, 2);
        } catch (\Throwable $e) {
            return ['yaml' => $body, 'raw' => true, 'err' => 'YAML 序列化失败: ' . $e->getMessage()];
        }

        return ['yaml' => $out, 'raw' => false, 'err' => ''];
    }

    /**
     * 注入 mieru 链式加速结构。
     * A：订阅为 proxy-providers 结构（当前 subconverter 输出即如此）→ 复制源 provider
     *    筛出落地节点并 override 注入 dialer-proxy；B：内联节点 → 节点级 dialer-proxy。
     */
    private function inject(array &$config, array $landingKeys, array $relayKeys, string $group, string $prefix): void
    {
        $landingRe = $this->toRegex($landingKeys);
        $relayRe = $this->toRegex($relayKeys);
        $isTarget = function (string $name) use ($landingKeys): bool {
            $lower = mb_strtolower($name);
            foreach ($landingKeys as $k) {
                if ($k !== '' && str_contains($lower, mb_strtolower($k))) return true;
            }
            return false;
        };

        $providers = $config['proxy-providers'] ?? [];
        $srcName = null;
        foreach ($providers as $name => $p) {
            if (is_array($p) && ($p['type'] ?? '') === 'http') { $srcName = $name; break; }
        }

        if ($srcName !== null) {
            // ---- A. provider 结构：复制源 provider，只留落地节点并挂前置 ----
            $landing = '__mieru_landing__';
            $landingProv = $providers[$srcName];
            $landingProv['filter'] = $landingRe;
            $landingProv['path'] = './providers/' . $landing . '.yaml';
            $landingProv['override'] = array_merge($landingProv['override'] ?? [], [
                'dialer-proxy' => $group,
                'additional-prefix' => $prefix,
            ]);
            $providers[$landing] = $landingProv;
            $config['proxy-providers'] = $providers;

            $groups = $config['proxy-groups'] ?? [];
            $groups[] = ['name' => $group, 'type' => 'select', 'proxies' => ['DIRECT'], 'use' => [$srcName], 'filter' => $relayRe];
            $groups[] = ['name' => $prefix . '加速落地', 'type' => 'select', 'use' => [$landing]];

            foreach ($groups as $gi => $g) {
                if (!is_array($g) || ($g['name'] ?? '') === $group) continue;
                if (in_array($landing, (array) ($g['use'] ?? []), true)) continue;
                // 主选择类组（filter 为 .*）：整组追加加速版节点
                if (in_array($srcName, (array) ($g['use'] ?? []), true) && ($g['filter'] ?? '') === '.*') {
                    $groups[$gi]['use'][] = $landing;
                }
                // 固定节点列表的组：在落地原名后插入加速版
                if (!empty($g['proxies']) && is_array($g['proxies'])) {
                    $out = [];
                    foreach ($g['proxies'] as $n) {
                        $out[] = $n;
                        if (is_string($n) && $isTarget($n)) $out[] = $prefix . $n;
                    }
                    $groups[$gi]['proxies'] = $out;
                }
            }
            $config['proxy-groups'] = $groups;
            return;
        }

        // ---- B. 内联节点结构（普通订阅，兼容） ----
        $proxies = $config['proxies'] ?? [];
        $relayNames = [];
        foreach ($proxies as $p) {
            if (!is_array($p)) continue;
            $lower = mb_strtolower((string) ($p['name'] ?? ''));
            foreach ($relayKeys as $k) {
                if ($k !== '' && str_contains($lower, mb_strtolower($k))) { $relayNames[] = $p['name']; break; }
            }
        }
        if (!$relayNames) return;

        $groups = $config['proxy-groups'] ?? [];
        $exists = false;
        foreach ($groups as $g) {
            if (is_array($g) && ($g['name'] ?? '') === $group) { $exists = true; break; }
        }
        if (!$exists) {
            $groups[] = ['name' => $group, 'type' => 'select', 'proxies' => $relayNames];
        }
        $config['proxy-groups'] = $groups;

        foreach ($proxies as $pi => $p) {
            if (!is_array($p)) continue;
            $name = (string) ($p['name'] ?? '');
            if (in_array($name, $relayNames, true)) continue;
            if ($isTarget($name)) $config['proxies'][$pi]['dialer-proxy'] = $group;
        }
    }

    /** 读取逗号分隔关键字配置为数组（去空项） */
    private function keywords(string $key, string $default): array
    {
        $raw = (string) admin_setting($key, $default);
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
    }

    /** 关键字数组 → mihomo filter 正则（忽略大小写，元字符转义） */
    private function toRegex(array $keys): string
    {
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = preg_quote($k, '/');
        }
        return '(?i)' . implode('|', $parts);
    }

    /** 纯文本响应（错误提示用） */
    private function raw(string $text, int $status)
    {
        return response($text, $status)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
