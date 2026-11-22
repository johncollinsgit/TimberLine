#!/usr/bin/env bash
# Install the reviewed player and publisher on the FRH host without touching feed contents.
set -euo pipefail
[[ "$(hostname)" = frh-web-1 ]]
base=/opt/frh/releases/20261121-frh-ebook-sender
release=/opt/frh/releases/20261122-frh-studio-player
drop=/etc/systemd/system/frh-public.service.d/92-frh-studio-player.conf
stage=$(cd -- "$(dirname "$0")" && pwd)
test "$(systemctl show frh-public -p WorkingDirectory --value)" = "$base"
test ! -e "$release"
test ! -e "$drop"
printf '%s  %s\n' 1090f4dbf085b6c4f95e2dd521b3931253d55b26c0104565769ffe9030457a4d "$base/public/app.js" | sha256sum -c -
install -d -m 755 "$release"
cp -a "$base/." "$release/"
python3 "$stage/install_frh_player.py" "$base/public/app.js" "$release/public/app.js"
install -m 0644 "$stage/publish_episode.py" "$release/src/publish_episode.py"
node_bin=/opt/node-v24.22.1-linux-x64/bin/node
"$node_bin" --check "$release/public/app.js"
(cd "$stage" && PYTHONDONTWRITEBYTECODE=1 python3 test_publish_episode.py)
(cd "$release" && "$node_bin" --test tests/*.test.mjs)
cat > "$drop.tmp" <<EOF
[Service]
WorkingDirectory=$release
ExecStart=
ExecStart=$node_bin $release/src/server.mjs
EOF
mv -T "$drop.tmp" "$drop"
rollback() {
    rm -f -- "$drop"
    systemctl daemon-reload
    systemctl restart frh-public
}
trap rollback ERR
systemctl daemon-reload
systemctl restart frh-public
curl -fsS --retry 5 --retry-all-errors --retry-delay 1 https://freerangehumanity.com/podcast -o /dev/null
curl -fsS --resolve freerangehumanity.com:443:127.0.0.1 https://freerangehumanity.com/app.js -o "$stage/served.js"
cmp "$stage/served.js" "$release/public/app.js"
for uri in /podcast/rss.xml /store /faith-through-the-forest /consultation; do
    curl -fsS --resolve freerangehumanity.com:443:127.0.0.1 "https://freerangehumanity.com$uri" -o /dev/null
done
systemctl is-active frh-public
trap - ERR
