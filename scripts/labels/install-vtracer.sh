#!/usr/bin/env bash
set -euo pipefail
# Build the pinned MIT-licensed converter into the deploy user's private shared storage.
task_tools="${1:?Pass the shared storage/app/label-tools directory}"
mkdir -p "$task_tools"
CARGO_HOME="$task_tools/cargo-cache" cargo install vtracer --version 0.6.5 --locked --root "$task_tools/build"
install -m 0755 "$task_tools/build/bin/vtracer" "$task_tools/vtracer-0.6.5"
"$task_tools/vtracer-0.6.5" --version
