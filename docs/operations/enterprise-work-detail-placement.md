# Work detail position

Projects and requests share one native dialog. Center is the initial position;
Center and Side change layout without replacing the active detail, comment draft,
file selection or in-flight upload. The header and Close control remain visible.
Phone layouts fill the available safe viewport. Escape/backdrop dismissal retain
the existing return-to-list, search, scroll and current-access behavior.

The optional browser preference contains only `center` or `side`, under the exact
public mount boundary/runtime/workspace/viewer key. Campus and owner mounts are
separate even when their independently stored numeric IDs repeat. Only the fixed
boundary enum is stored; proposal URLs and tokens never become preference keys. Invalid keys or unavailable storage use Center. No
comment, file, project, permission or credential is written by this preference.

The centered desktop view places work information beside comments, files and
photos. Attach files and Add photo both use the existing authorized native upload
flow. Existing JPEG/PNG/PDF/text limits, 10 MB size, resumable chunks, MIME checks,
private storage and clean-scan quarantine apply unchanged. Photo capture is a
browser hint, not an external camera service or automatic upload permission.
Files held in quarantine have no Download control. Scanner/provider flags are not
enabled by this presentation.

Normal Enterprise CI registers the bounded preference tests and the Roll Call
browser scenario exercises Center/Side with an unsent draft and selected photo
while a real native upload chunk is delayed, then verifies native comment/photo
persistence after reload in Chromium and WebKit. It also checks request drafts,
Escape, list search and phone/tablet viewport fit. These are local synthetic HTTP
checks, not hosted or physical-device acceptance. Root release authority must
compose the packet with the project/queue and Roll Call packets and run normal
final-head gates before deployment.
