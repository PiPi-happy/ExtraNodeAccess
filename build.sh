#!/bin/bash
# ExtraNodeAccess 插件打包脚本
# 产物：extra-node-access.zip（zip 内含 ExtraNodeAccess/ 目录，符合 Xboard upload 要求）
set -e
cd "$(dirname "$0")"

VERSION=$(python3 -c 'import json;print(json.load(open("ExtraNodeAccess/config.json"))["version"])')
rm -f extra-node-access.zip
zip -r extra-node-access.zip ExtraNodeAccess -x '*.DS_Store' -x '__MACOSX*' >/dev/null

echo "✅ 打包完成：extra-node-access.zip (v${VERSION})"
echo "   上传到 Xboard 后台 → 插件管理 → 上传插件"
ls -lh extra-node-access.zip | awk '{print "   大小:", $5}'
