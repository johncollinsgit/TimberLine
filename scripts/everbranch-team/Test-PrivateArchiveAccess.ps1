# Read-only verification. Run against the private discovery parent so source,
# manifest, report and restored copy are included. This never changes an ACL.
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $LiteralPath,
    [string] $AllowedUserSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
)

$ErrorActionPreference = 'Stop'
$allowedSids = @($AllowedUserSid, 'S-1-5-18')
$taskRoot = Get-Item -LiteralPath $LiteralPath -Force
$pending = [System.Collections.Generic.Queue[System.IO.FileSystemInfo]]::new()
$pending.Enqueue($taskRoot)
$checkedItems = 0
$unexpectedOwners = 0
$unexpectedAllowEntries = 0
$reparsePoints = 0

while ($pending.Count -gt 0) {
    $item = $pending.Dequeue()
    if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
        $reparsePoints++
        continue
    }
    $acl = Get-Acl -LiteralPath $item.FullName
    $owner = $acl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value
    if ($owner -notin $allowedSids) { $unexpectedOwners++ }
    foreach ($rule in $acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) {
        if ($rule.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Allow -and
            $rule.IdentityReference.Value -notin $allowedSids) {
            $unexpectedAllowEntries++
        }
    }
    $checkedItems++
    if ($item.PSIsContainer) {
        foreach ($child in Get-ChildItem -LiteralPath $item.FullName -Force) { $pending.Enqueue($child) }
    }
}

$verified = $checkedItems -gt 0 -and $unexpectedOwners -eq 0 -and $unexpectedAllowEntries -eq 0 -and $reparsePoints -eq 0
[pscustomobject]@{
    verified = $verified
    checked_items = $checkedItems
    unexpected_owner_count = $unexpectedOwners
    unexpected_allow_entry_count = $unexpectedAllowEntries
    rejected_reparse_point_count = $reparsePoints
    checked_at = [DateTime]::UtcNow.ToString('o')
} | ConvertTo-Json -Compress
if (-not $verified) { exit 2 }
