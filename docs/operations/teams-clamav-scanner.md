# Teams private upload scanner

The Teams upload service already quarantines unverified files. The November 8
pilot inspection found the scanner flag off and no reachable ClamAV daemon.
Photos cannot become downloadable until an actual scanner is installed and
verified. Source/browser tests do not establish this prerequisite.

This is a shared server prerequisite for all tenants. Event and task permissions,
the explicit upload target, file hashes, MIME checks, quotas and fresh download
admission continue to apply independently.

## Operator action prepared for review

`scripts/ops/install-teams-clamav.sh` is a versioned, root-only Ubuntu installer.
Its default `--plan` mode makes no changes. Root-only `--preflight` refreshes
package indexes and prints current capacity, candidate origins/versions and the
simulated dependency plan without installing packages or changing services.
`--apply` repeats that preflight, then installs the exact selected ClamAV versions,
configures dedicated services, obtains official signatures and runs synthetic
local checks. It does not edit app `.env`, app files, PHP, nginx or release IDs.

The current Forge SSH account cannot install operating-system packages; its
limited sudo rules must not be extended or bypassed by application code. A root
operator must run the reviewed script through an authorized server console.
This manual action is required by the server's current privilege boundary.

After the reviewed source is available on the server, use its exact checkout or
verified release path. First review `--plan`, the script, companion Python client
and the four generated configuration files. Record the source commit/hash.
In the authorized root console:

```sh
id -u
# Must print 0. Substitute the exact reviewed checkout below.
bash /path/to/reviewed-checkout/scripts/ops/install-teams-clamav.sh --plan
bash /path/to/reviewed-checkout/scripts/ops/install-teams-clamav.sh --preflight
# Review the candidate versions, repository origins, dependency changes and RAM.
bash /path/to/reviewed-checkout/scripts/ops/install-teams-clamav.sh --apply
```

Do not pipe an unreviewed network response into a root shell. The script refuses
an existing ClamAV installation it does not manage. An operator must review such
an installation separately rather than overwriting another application's scanner.

Ubuntu's packages derive from the Debian packages; the prepared command installs
`clamav`, `clamav-daemon` and `clamav-freshclam`. Distribution paths and patches
can differ from upstream, so successful startup and protocol tests are required.
[ClamAV package documentation](https://docs.clamav.net/manual/Installing/Packages.html)

Ubuntu's packaged AppArmor profiles allow the distribution's standard config
names. The dedicated `everbranch-*.conf` names therefore need explicit read
access. When AppArmor is active, the installer preserves existing local include
content and adds one marked block to `local/usr.bin.freshclam` (reads for both
dedicated configs, including `NotifyClamd`) and `local/usr.sbin.clamd` (read for
its dedicated config). Existing database permissions remain unchanged; no new
directory wildcard or write grant is added. Both candidate profiles compile with
kernel load and cache writes disabled before any local include is installed.
Original local files are backed up and only these two profiles are reloaded.

The installer never disables a profile or switches it to complain mode. Missing,
linked, disabled or unexpectedly structured package profiles require operator
review; it does not replace their policy with a generated unconfined profile.
Read-only validation of the exact additional rules succeeded using the installed
Ubuntu parser on the pilot host; that compile-only check does not itself change
the kernel's active profiles or establish successful scanning.
[Ubuntu AppArmor local includes](https://ubuntu.com/server/docs/how-to/security/apparmor/),
[AppArmor parser validation and targeted reload](https://manpages.ubuntu.com/manpages/noble/man8/apparmor_parser.8.html)

Candidates for all three ClamAV packages must be at least upstream 1.4 before
any package install. The Debian epoch is removed for that minimum comparison;
the original full candidate version remains pinned for installation. The normal
APT trust checks remain enabled. A missing/older candidate, failed metadata
refresh or unsuccessful simulation aborts. Simulation and installation both
refuse package removals. Simulation is not a package-manager lock: the install
still revalidates the pinned packages against current state.
[APT simulation and no-remove options](https://manpages.ubuntu.com/manpages/noble/man8/apt-get.8.html),
[dpkg version comparison](https://manpages.ubuntu.com/manpages/noble/man1/dpkg.1.html)

## Service boundaries and capacity

The scanner runs as the package's unprivileged `clamav` account. It listens on
**127.0.0.1:3310 only**; its systemd network rules also restrict it to loopback.
ClamD's TCP protocol has no authentication or encryption. Do not open this port
in a firewall or bind it to a public/private network interface.
[ClamAV scanning documentation](https://docs.clamav.net/manual/Usage/Scanning.html)

The prepared settings are deployment choices, not measured throughput guarantees:

| Setting | Prepared value |
| --- | --- |
| Scanner service | `everbranch-clamd.service` |
| Updater service | `everbranch-freshclam.service` |
| Scanner/updater identity | `clamav:clamav`, no process capabilities |
| Upload stream and individual file limit | 10 MiB, matching `team_work.file_max_bytes` |
| Expanded scan data / recursion / contained files | 32 MiB / 12 / 1,000 |
| Scan workers / total queue | 2 / 8 |
| Engine scan budget | `MaxScanTime 8000` milliseconds |
| Read / initial-command timeouts | 10 / 5 seconds |
| Exceeded engine limits | `AlertExceedsMax yes` |
| Concurrent engine database reload | Disabled; scans can pause during reload |
| Scanner RAM high / maximum | 2 / 3 GiB |
| Updater RAM high / maximum | 1 / 2 GiB |
| Initial signature validation | Transient `clamav` unit; same updater RAM/CPU limits; 15-minute runtime limit |
| Scanner / updater CPU quota | 100% / 50% of one CPU |
| Signature update checks | 12 per day |
| Maximum accepted loaded signature age | 7 days |

The upstream sample documents these engine limits and the extra engine allocated
by concurrent reloads. `MaxScanTime` is not a universal hard wall-clock limit for
every format. App socket timeouts and non-clean/unavailable outcomes still leave
files rejected or quarantined; scan limits must not be disabled to return clean.
[Reviewed ClamAV 1.4.3 configuration](https://github.com/Cisco-Talos/clamav/blob/clamav-1.4.3/etc/clamd.conf.sample)

ClamAV recommends at least 3 GiB of RAM and 5 GiB of free disk for the scanner,
with additional resources for other applications. The installer requires at
least 4 GiB currently available RAM and 5 GiB free disk before proceeding.
The pilot's reported 8 GiB host and approximately 5.4 GiB available RAM need
monitoring during signature validation/reloads, alongside PHP and MySQL. The
resource ceilings are safeguards, not proof that this host has sufficient spare
capacity under production load. If limits prevent healthy scanning, keep the app
flag off and review server capacity.
[ClamAV requirements](https://docs.clamav.net/)

The first FreshClam download/validation is also bounded, rather than running as
an uncapped root child. A temporary `everbranch-freshclam-bootstrap` service runs
as `clamav:clamav`, with updater-equivalent filesystem restrictions, no process
capabilities, a 2 GiB maximum and 50% CPU quota. Both its runtime and the waiting
client are limited to 15 minutes; a timed-out service has a 30-second stop grace.
Its exit result must succeed before starting the scanner. No sudo rules, package
repositories, firewall rules or system-wide security disablement are introduced.
[systemd-run wait, identity and resource properties](https://manpages.ubuntu.com/manpages/noble/man1/systemd-run.1.html)

### Measure capacity before activation

The observed pilot host has 8 GiB total RAM, about 2.5 GiB already used and
about 5.4 GiB available. These are baseline observations, not a reason to buy a
larger server and not proof that both configured ceilings can be reached safely.
Keep `TEAM_FILE_SCANNER_ENABLED` off while measuring:

1. Before `--apply`, record its baseline output and several timed `/ready`
   requests. Start memory sampling before signature download so initial database
   validation and daemon startup are included. Record `MemAvailable`, swap use,
   per-process RSS/high-water RSS and unit `MemoryCurrent`/`MemoryPeak`; only
   process names and scalar measurements belong in these logs.
2. During the bootstrap and first scanner startup, sample at least every second.
   Record the transient unit's peak while it exists and preserve the
   `systemd-run --wait` result in the operator log. It is collected on completion.
   Capture persistent scanner/updater peaks afterward. An OOM, timeout or failed
   signature test is a failure, not a reason to raise limits silently.
3. With the local daemon healthy, run the companion `--boundaries` check. Test a
   local engine reload using the protocol's `RELOAD` command and keep measuring
   through successful benign/EICAR checks afterward. `ConcurrentDatabaseReload`
   remains off. For concurrent updater-like engine pressure, run a bounded,
   unprivileged `clamscan --database=/var/lib/clamav --no-summary /dev/null`
   transient service with the same 2 GiB/50% CPU limits. This loads trusted local
   signatures without forcing CDN downloads or scanning customer files; label it
   a concurrent engine-load test, not an actual new signature download.
4. During all phases, repeatedly time `/ready` and a permitted read-only Teams
   view. Require HTTP 200 and the approved release, no OOM or service restart
   loop, no sustained swap-in, and headroom for normal PHP/MySQL and deployment
   work. As a conservative pilot operating threshold, retain at least 2 GiB
   `MemAvailable` and no sustained `/ready` slowdown beyond twice its baseline
   p95 or two seconds, whichever is larger. This is a local acceptance threshold,
   not an upstream sizing guarantee. Record actual maxima and workload limits.
5. Keep the app flag off if any phase is unhealthy; review the measured cause
   before changing limits or capacity. Observe the first real scheduled signature
   update as well. No service ceiling or successful PING alone establishes
   capacity for every future signature set or production workload.

Useful scalar diagnostics during sampling (the bootstrap unit is temporary):

```sh
awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ {print}' /proc/meminfo
ps -C clamd,freshclam,clamscan -o pid=,comm=,rss=
systemctl show everbranch-freshclam-bootstrap.service everbranch-clamd.service everbranch-freshclam.service -p MemoryCurrent -p MemoryPeak -p Result -p NRestarts
curl --silent --show-error --max-time 5 --output /dev/null --write-out 'ready_http=%{http_code} total_seconds=%{time_total}\n' https://app.theeverbranch.com/ready
```

If a systemd version does not report a peak property, retain the sampled
`/proc/<pid>/status` `VmHWM` and cgroup memory peak readings while each process
exists. Do not relabel a sparse sample as an exact peak. These are read-only
diagnostics; successful real measurements must be recorded separately.
[ClamD reload protocol](https://docs.clamav.net/manual/Usage/ClamdProtocol.html)

Dedicated units replace the distribution's duplicate daemon/socket/updater units
on this new installation, avoiding multiple scanning engines. Existing managed
configs are backed up under `/var/backups/everbranch-clamav/<UTC timestamp>`.
Database updates use FreshClam's official signature distribution and notify the
local daemon. No customer file is sent to a signature server or scanning service.
[Signature updates](https://docs.clamav.net/manual/Usage/SignatureManagement.html),
[FreshClam daemon/notification options](https://github.com/Cisco-Talos/clamav/blob/clamav-1.4.3/docs/man/freshclam.1.in)

## Verify before activating uploads

The companion client can run without root on the same server:

```sh
python3 /path/to/reviewed-checkout/scripts/ops/check-teams-clamav.py --boundaries
systemctl is-active everbranch-clamd.service everbranch-freshclam.service
ss -ltn 'sport = :3310'
```

Require a successful JSON result, both units active and a listener only at
`127.0.0.1:3310`. The client checks NUL-framed PING/VERSION, the loaded database
date, a benign stream, the harmless standard EICAR antivirus test, a clean 10 MiB
stream and explicit rejection of 10 MiB plus one byte. It creates no customer or
test file on disk and communicates only with loopback. Unknown/truncated replies,
stale signatures, missed EICAR detection or boundary mismatches fail the check.
The daemon/updater units set `TZ=UTC` for the database date diagnostic.
[ClamD protocol](https://docs.clamav.net/manual/Usage/ClamdProtocol.html)

These tests prove the installed local daemon's observed behavior. Local script
syntax and protocol-client unit tests alone do not prove a real scanner exists.
FreshClam's initial database download can take several minutes. Respect reported
CDN cooldowns; do not repeatedly force downloads or substitute an untrusted mirror.

After the real protocol and measured capacity checks pass, set these existing settings in Forge's reviewed
environment management:

```dotenv
TEAM_FILE_SCANNER_ENABLED=true
TEAM_CLAMD_HOST=127.0.0.1
TEAM_CLAMD_PORT=3310
```

Apply the configuration through the normal protected GitHub/Forge release/config
process. Do not hotpatch a live release or run an ad hoc cache rebuild that loses
its release context. Confirm `/ready` matches the approved release, confirm the
runtime scanner settings, and perform a controlled authorized upload: a benign
photo must become ready and its protected preview must work. An antivirus test
must remain rejected and unavailable. Do not publish a test update to Asana merely
to test scanning. Record the real acceptance separately from source tests.

## Failure and recovery

- Inspect `journalctl -u everbranch-clamd -u everbranch-freshclam`, service memory
  usage and the non-root health JSON. Keep customer content out of diagnostic logs.
- Unavailable, timed-out or out-of-memory scanning must retain quarantine. Never
  SQL-edit an upload to `ready`, replace the scanner with an always-clean stub or
  bypass MIME/hash checks. Existing ready bytes retain fresh permission checks.
- After scanner recovery, retry completion for the original **quarantined** upload
  ID; the existing service rescans the same immutable bytes. Rejected content
  cannot be promoted through that retry.
- If initial setup or definition downloads fail, the installer exits nonzero and
  leaves the app flag unchanged. Review service/config logs before retrying.
- To disable scanning service use, first set the app flag off through the same
  protected config process. Then a root operator can stop the two dedicated units.
  Preserve upload evidence, quarantine files, signature data and configuration
  backups. Do not re-enable a different default daemon or open the network port as
  an automatic rollback shortcut.

Verification performed for the prepared source: shell syntax, Python syntax and
protocol-client unit tests. Production package installation, real daemon checks,
runtime flag activation and actual photo acceptance remain operator work.
