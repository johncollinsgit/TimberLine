#!/usr/bin/env bash
# Everbranch Teams scanner setup v1. Root operator action; never run by app PHP.
set -euo pipefail

mode="${1:---plan}"
[[ $# -le 1 ]] || { echo "Usage: $0 --plan | --preflight | --apply" >&2; exit 64; }
if [[ "$mode" != '--apply' && "$mode" != '--preflight' ]]; then
  if [[ "$mode" != '--plan' && "$mode" != '--help' ]]; then
    echo "Usage: $0 --plan | --preflight | --apply" >&2
    exit 64
  fi
  cat <<'PLAN'
Everbranch Teams ClamAV operator plan v1 (no changes in --plan mode):
  1. Require root on Ubuntu, >=4 GiB available RAM and >=5 GiB free /var/lib disk.
  2. Refuse an existing ClamAV installation not managed by this setup.
  3. Print RAM/swap/disk/process baseline, refresh trusted Ubuntu package indexes,
     require ClamAV candidates >=1.4 and review the simulated dependency plan.
     --preflight stops here without installing packages or changing services.
     --apply repeats the preflight, then installs the exact selected versions.
  4. Configure dedicated clamav-user systemd scanner/updater units; disable
     distribution duplicate scanner/socket/updater units to avoid two engines.
  5. Listen only on 127.0.0.1:3310. Limit streams/files to 10 MiB; flag exceeded
     scan limits. Bound scanner resources and disable concurrent engine reloads.
  6. Download official signatures, enable scheduled freshclam updates, and test
     PING, loaded database date, benign/EICAR and upload-size boundaries locally.
  7. Leave all app flags, .env, release files, PHP and nginx untouched.

Root can first run --preflight, then review this exact version before --apply.
Package/service ceilings are not capacity proof: measure signature validation,
startup and reload peaks alongside app readiness before enabling the app flag.
Read docs/operations/teams-clamav-scanner.md before running it on a server.
PLAN
  exit 0
fi

if [[ "$(id -u)" != 0 ]]; then
  echo "A root operator must run --apply or --preflight. This script does not elevate privileges." >&2
  exit 77
fi
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
[[ -r /etc/os-release ]] || { echo 'Ubuntu is required.' >&2; exit 1; }
# /etc/os-release is the root-controlled OS identity file.
source /etc/os-release
[[ "${ID:-}" == ubuntu ]] || { echo 'Ubuntu is required.' >&2; exit 1; }
command -v systemctl >/dev/null
command -v systemd-run >/dev/null
command -v apt-get >/dev/null
command -v apt-cache >/dev/null
command -v dpkg >/dev/null

ops_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "$ops_dir/check-teams-clamav.py" ]] || { echo 'Keep the reviewed health script beside this installer.' >&2; exit 1; }
managed_header='# Managed by Everbranch Teams scanner setup v1.'
managed_files=(/etc/clamav/everbranch-clamd.conf /etc/clamav/everbranch-freshclam.conf /etc/systemd/system/everbranch-clamd.service /etc/systemd/system/everbranch-freshclam.service)
for config_file in "${managed_files[@]}"; do
  if [[ -e "$config_file" || -L "$config_file" ]]; then
    [[ ! -L "$config_file" ]] && head -n 1 "$config_file" | grep -Fxq "$managed_header" || { echo "Refusing to replace unmanaged $config_file" >&2; exit 1; }
  fi
done
if command -v clamd >/dev/null && [[ ! -f /etc/systemd/system/everbranch-clamd.service ]]; then
  echo 'Existing ClamAV is not managed by this setup; review it manually.' >&2
  exit 1
fi
available_kib="$(awk '/^MemAvailable:/ { print $2 }' /proc/meminfo)"
free_disk_kib="$(df -Pk /var/lib | awk 'NR==2 { print $4 }')"
echo 'Capacity baseline (ceilings do not establish available production headroom):'
awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ { print }' /proc/meminfo
df -Ph /var/lib
echo 'Largest resident processes: command name and RSS KiB only (no arguments).'
ps -eo comm=,rss= --sort=-rss | awk 'NR<=10 { print }'
[[ "$available_kib" =~ ^[0-9]+$ && "$available_kib" -ge 4194304 ]] || { echo 'Need at least 4 GiB currently available RAM; app memory must remain available too.' >&2; exit 1; }
[[ "$free_disk_kib" =~ ^[0-9]+$ && "$free_disk_kib" -ge 5242880 ]] || { echo 'Need at least 5 GiB free disk under /var/lib.' >&2; exit 1; }

# BEGIN reviewed package preflight
prepare_packages() {
  local package policy candidate upstream
  install_specs=()
  apt-get update
  for package in clamav clamav-daemon clamav-freshclam; do
    policy="$(LC_ALL=C apt-cache policy "$package")"
    printf '%s\n' "$policy"
    candidate="$(awk '/^[[:space:]]*Candidate:/ { print $2; exit }' <<< "$policy")"
    [[ -n "$candidate" && "$candidate" != '(none)' ]] || { echo "No trusted configured candidate for $package; nothing installed." >&2; return 1; }
    # A Debian epoch must not make an older upstream engine look supported.
    upstream="${candidate#*:}"
    dpkg --compare-versions "$upstream" ge 1.4.0 || { echo "$package candidate $candidate is below required ClamAV 1.4; nothing installed." >&2; return 1; }
    install_specs+=("$package=$candidate")
  done
  install_specs+=(python3 ca-certificates iproute2)
  echo 'Simulated exact package/dependency plan (no package removals permitted):'
  LC_ALL=C apt-get --simulate --no-install-recommends --no-remove install "${install_specs[@]}"
}
# END reviewed package preflight
prepare_packages
if [[ "$mode" == '--preflight' ]]; then
  echo 'Preflight complete. Package indexes refreshed; no packages installed, services changed, or app flags edited.'
  exit 0
fi
DEBIAN_FRONTEND=noninteractive LC_ALL=C apt-get install -y --no-install-recommends --no-remove "${install_specs[@]}"
scanner_version="$(/usr/sbin/clamd --version)"
python3 - "$scanner_version" <<'PY'
import re, sys
match = re.search(r'ClamAV (\d+)\.(\d+)\.(\d+)', sys.argv[1])
if not match or tuple(map(int, match.groups())) < (1, 4, 0):
    raise SystemExit('ClamAV 1.4+ is required for this reviewed configuration; keep the app scanner flag off.')
PY
[[ "$(id -u clamav)" != 0 ]] || { echo 'clamav must be an unprivileged account.' >&2; exit 1; }

for unit in clamav-daemon.socket clamav-daemon.service clamav-freshclam.service; do
  if systemctl cat "$unit" >/dev/null 2>&1; then
    systemctl disable --now "$unit"
  fi
done
systemctl stop everbranch-clamd.service everbranch-freshclam.service 2>/dev/null || true
backup_dir="/var/backups/everbranch-clamav/$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$backup_dir"
for config_file in "${managed_files[@]}"; do
  if [[ -f "$config_file" ]]; then cp -p "$config_file" "$backup_dir/$(basename "$config_file")"; fi
done
install -d -o clamav -g clamav -m 0750 /var/lib/clamav
work_dir="$(mktemp -d /tmp/everbranch-clamav.XXXXXXXX)"
cleanup() { rm -rf -- "$work_dir"; }
trap cleanup EXIT

cat > "$work_dir/everbranch-clamd.conf" <<'CLAMD'
# Managed by Everbranch Teams scanner setup v1.
Foreground yes
DatabaseDirectory /var/lib/clamav
TCPSocket 3310
TCPAddr 127.0.0.1
MaxThreads 2
MaxQueue 8
MaxConnectionQueueLength 8
CommandReadTimeout 5
ReadTimeout 10
SendBufTimeout 500
StreamMaxLength 10M
MaxFileSize 10M
MaxScanSize 32M
MaxRecursion 12
MaxFiles 1000
MaxScanTime 8000
AlertExceedsMax yes
ConcurrentDatabaseReload no
SelfCheck 600
FailIfCvdOlderThan 7
ExitOnOOM yes
LogSyslog yes
LogTime yes
CLAMD
cat > "$work_dir/everbranch-freshclam.conf" <<'FRESHCLAM'
# Managed by Everbranch Teams scanner setup v1.
DatabaseDirectory /var/lib/clamav
DatabaseOwner clamav
DatabaseMirror database.clamav.net
Checks 12
MaxAttempts 3
ConnectTimeout 15
ReceiveTimeout 30
TestDatabases yes
NotifyClamd /etc/clamav/everbranch-clamd.conf
LogSyslog yes
LogTime yes
FRESHCLAM
cat > "$work_dir/everbranch-clamd.service" <<'SCANNER_UNIT'
# Managed by Everbranch Teams scanner setup v1.
[Unit]
Description=Everbranch Teams local upload scanner
After=network.target
StartLimitIntervalSec=3600
StartLimitBurst=3

[Service]
Type=simple
User=clamav
Group=clamav
Environment=TZ=UTC
ExecStart=/usr/sbin/clamd --config-file=/etc/clamav/everbranch-clamd.conf --foreground=true
Restart=on-failure
RestartSec=30
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
CapabilityBoundingSet=
RestrictAddressFamilies=AF_INET AF_UNIX
IPAddressDeny=any
IPAddressAllow=localhost
UMask=0027
MemoryHigh=2G
MemoryMax=3G
CPUQuota=100%
TasksMax=64
LimitNOFILE=1024

[Install]
WantedBy=multi-user.target
SCANNER_UNIT
cat > "$work_dir/everbranch-freshclam.service" <<'UPDATER_UNIT'
# Managed by Everbranch Teams scanner setup v1.
[Unit]
Description=Everbranch Teams ClamAV signature updater
Wants=network-online.target
After=network-online.target everbranch-clamd.service
StartLimitIntervalSec=3600
StartLimitBurst=3

[Service]
Type=simple
User=clamav
Group=clamav
Environment=TZ=UTC
ExecStart=/usr/bin/freshclam --daemon --foreground --config-file=/etc/clamav/everbranch-freshclam.conf
Restart=on-failure
RestartSec=60
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ReadWritePaths=/var/lib/clamav
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
CapabilityBoundingSet=
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
UMask=0027
MemoryHigh=1G
MemoryMax=2G
CPUQuota=50%
TasksMax=64

[Install]
WantedBy=multi-user.target
UPDATER_UNIT
for name in everbranch-clamd.conf everbranch-freshclam.conf; do install -o root -g root -m 0644 "$work_dir/$name" "/etc/clamav/$name"; done
for name in everbranch-clamd.service everbranch-freshclam.service; do install -o root -g root -m 0644 "$work_dir/$name" "/etc/systemd/system/$name"; done
# Ubuntu confines the packaged binaries to their default config filenames.
# Retain those profiles and all existing local policy; add only our config reads.
if [[ -r /sys/module/apparmor/parameters/enabled ]] && grep -q '^Y' /sys/module/apparmor/parameters/enabled; then
  command -v apparmor_parser >/dev/null
  [[ -d /etc/apparmor.d/local && ! -L /etc/apparmor.d/local ]] || { echo 'Review the AppArmor local include directory before proceeding.' >&2; exit 1; }
  python3 - "$work_dir" <<'PY'
from pathlib import Path
import sys

# BEGIN AppArmor local merge helper
def merge_local_policy(current, rules):
    start = '# BEGIN Everbranch Teams scanner config access v1'
    end = '# END Everbranch Teams scanner config access v1'
    block = start + '\n' + '\n'.join(rules) + '\n' + end
    if start in current or end in current:
        if current.count(start) != 1 or current.count(end) != 1 or current.index(start) > current.index(end):
            raise ValueError('Review malformed or duplicate Everbranch AppArmor markers.')
        first, last = current.index(start), current.index(end) + len(end)
        return current[:first] + block + current[last:]
    return current + ('' if not current or current.endswith('\n') else '\n') + block + '\n'
# END AppArmor local merge helper

work = Path(sys.argv[1])
rules = {
    'usr.bin.freshclam': ['/etc/clamav/everbranch-freshclam.conf r,', '/etc/clamav/everbranch-clamd.conf r,'],
    'usr.sbin.clamd': ['/etc/clamav/everbranch-clamd.conf r,'],
}
for name, access in rules.items():
    profile, local = Path('/etc/apparmor.d') / name, Path('/etc/apparmor.d/local') / name
    if profile.is_symlink() or not profile.is_file() or (Path('/etc/apparmor.d/disable') / name).exists():
        raise SystemExit('Review absent, linked or disabled ClamAV AppArmor profiles before proceeding.')
    if local.is_symlink():
        raise SystemExit('Review the linked ClamAV AppArmor local include before proceeding.')
    original = profile.read_text()
    include = '#include <local/' + name + '>'
    if original.count(include) != 1:
        raise SystemExit('The packaged AppArmor local include changed; review before proceeding.')
    merged = merge_local_policy(local.read_text() if local.exists() else '', access)
    (work / ('aa-local-' + name)).write_text(merged)
    # Compile against the merged contents before changing any active include.
    (work / ('aa-profile-' + name)).write_text(original.replace(include, merged, 1))
PY
  for name in usr.bin.freshclam usr.sbin.clamd; do
    apparmor_parser --skip-kernel-load --skip-cache --base=/etc/apparmor.d "$work_dir/aa-profile-$name"
  done
  for name in usr.bin.freshclam usr.sbin.clamd; do
    if [[ -f "/etc/apparmor.d/local/$name" ]]; then cp -p "/etc/apparmor.d/local/$name" "$backup_dir/apparmor-local-$name"; fi
    install -o root -g root -m 0644 "$work_dir/aa-local-$name" "/etc/apparmor.d/local/$name"
  done
  for name in usr.bin.freshclam usr.sbin.clamd; do
    apparmor_parser --replace --skip-cache --base=/etc/apparmor.d "/etc/apparmor.d/$name"
  done
fi
systemctl daemon-reload
# Official signature downloads only. No app/customer files are submitted.
# BEGIN bounded signature bootstrap
bootstrap_signatures() {
  # Validation loads an engine too: the first download needs the same bounds as
  # the later updater, before the scanning daemon is started.
  timeout --signal=TERM 15m systemd-run --wait --pipe --collect --service-type=exec \
    --unit=everbranch-freshclam-bootstrap --uid=clamav --gid=clamav \
    --property=RuntimeMaxSec=15min --property=TimeoutStopSec=30s \
    --property=MemoryAccounting=yes --property=MemoryHigh=1G --property=MemoryMax=2G \
    --property=CPUQuota=50% --property=TasksMax=64 --property=Environment=TZ=UTC \
    --property=NoNewPrivileges=yes --property=PrivateTmp=yes --property=PrivateDevices=yes \
    --property=ProtectSystem=strict --property=ReadWritePaths=/var/lib/clamav \
    --property=ProtectHome=yes --property=ProtectKernelTunables=yes \
    --property=ProtectKernelModules=yes --property=ProtectControlGroups=yes \
    --property=RestrictSUIDSGID=yes --property=CapabilityBoundingSet= \
    '--property=RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX' --property=UMask=0027 \
    /usr/bin/freshclam --config-file=/etc/clamav/everbranch-freshclam.conf --stdout
}
# END bounded signature bootstrap
bootstrap_signatures
systemctl enable --now everbranch-clamd.service
ready=0
for attempt in $(seq 1 90); do
  if python3 - <<'PY'
import socket
try:
    with socket.create_connection(('127.0.0.1', 3310), timeout=1) as client:
        client.settimeout(1); client.sendall(b'zPING\0')
        raise SystemExit(0 if client.recv(16) == b'PONG\0' else 1)
except OSError:
    raise SystemExit(1)
PY
  then ready=1; break; fi
  sleep 2
done
[[ "$ready" == 1 ]] || { echo 'Scanner did not become ready. Inspect journalctl -u everbranch-clamd; app flag remains unchanged.' >&2; exit 1; }
python3 - <<'PY'
import subprocess
lines = subprocess.check_output(['ss', '-H', '-ltn', 'sport = :3310'], text=True).splitlines()
if not lines or any(line.split()[3] != '127.0.0.1:3310' for line in lines):
    raise SystemExit('Scanner port is absent or not strictly IPv4 loopback; do not enable the app flag.')
PY
python3 "$ops_dir/check-teams-clamav.py" --boundaries
systemctl enable --now everbranch-freshclam.service
systemctl is-active --quiet everbranch-clamd.service everbranch-freshclam.service
echo "Scanner checks passed. Configuration backups: $backup_dir"
echo 'Capacity acceptance is still required: compare baseline and signature/startup/reload peaks, app /ready response times, and memory headroom. Resource ceilings alone are not acceptance.'
echo 'App flag remains unchanged. Set the reviewed TEAM_FILE_SCANNER_ENABLED/TEAM_CLAMD settings in Forge, then use the protected release/config process and verify readiness plus a real authorized upload.'
