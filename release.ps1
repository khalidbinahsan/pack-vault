<#
.SYNOPSIS
    Release a new version of Packvault to GitHub and WordPress.org.

.DESCRIPTION
    Before running, write the changelog entry for the new version in readme.txt:

        == Changelog ==

        = 1.1.0 =
        * What changed.

    Then run from the plugin folder:

        .\release.ps1 1.1.0

    The script will:
      1. Check everything is ready (changelog, version order, tag not used yet).
      2. Bump the version in pack-vault.php, readme.txt, package.json, package-lock.json.
      3. Run "npm run build" (and "php -l" if PHP is found).
      4. Ask for confirmation.
      5. Commit, tag and push to GitHub.
      6. Copy the plugin into SVN trunk, add/remove changed files, create tags/<version>
         and commit to WordPress.org (asks for your SVN password).

.PARAMETER Version
    New version number, e.g. 1.1.0

.PARAMETER Message
    Short description used in commit messages. Default: "Release <version>".

.PARAMETER SvnDir
    SVN working copy. Default: C:\svn\pack-vault

.PARAMETER SvnUser
    WordPress.org SVN username. Default: khalidbin

.PARAMETER SkipSvn
    Do everything except the WordPress.org upload.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string]$Version,

    [string]$Message = '',
    [string]$SvnDir = 'C:\svn\pack-vault',
    [string]$SvnUser = 'khalidbin',
    [switch]$SkipSvn
)

$ErrorActionPreference = 'Stop'
$PluginDir = $PSScriptRoot
$Utf8NoBom = New-Object System.Text.UTF8Encoding $false
if (-not $Message) { $Message = "Release $Version" } else { $Message = "Release ${Version}: $Message" }

# ---------------------------------------------------------------- helpers

function Step($text) { Write-Host ''; Write-Host "==> $text" -ForegroundColor Cyan }
function Ok($text) { Write-Host "    $text" -ForegroundColor Green }
function Fail($text) { Write-Host ''; Write-Host "ERROR: $text" -ForegroundColor Red; exit 1 }

# Run a native command and stop if it fails.
# A plain function (no param block) so flags like -q and --silent pass through untouched.
# Use npm.cmd, not npm: npm's PowerShell wrapper (npm.ps1) re-reads the raw command
# line, which breaks when it is called from inside a function like this one.
function Run {
    $exe = $args[0]
    $rest = @($args | Select-Object -Skip 1)
    & $exe @rest
    if ($LASTEXITCODE -ne 0) { Fail "'$exe $($rest -join ' ')' failed (exit code $LASTEXITCODE)." }
}

function Read-Utf8($path) { [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8) }
function Write-Utf8($path, $text) { [System.IO.File]::WriteAllText($path, $text, $Utf8NoBom) }

# Replace the first $count matches of $pattern; fail if fewer were found.
function Replace-In {
    param([string]$Path, [string]$Pattern, [string]$Replacement, [int]$Count = 1)
    $text = Read-Utf8 $Path
    $regex = New-Object System.Text.RegularExpressions.Regex($Pattern, 'Multiline')
    $found = $regex.Matches($text).Count
    if ($found -lt $Count) { Fail "Expected $Count match(es) of '$Pattern' in $(Split-Path $Path -Leaf), found $found." }
    Write-Utf8 $Path ($regex.Replace($text, $Replacement, $Count))
    Ok "$(Split-Path $Path -Leaf)"
}

# Make svn/git/npm installed after this shell was opened visible.
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User') + ';' + $env:Path

Set-Location $PluginDir
$MainFile = Join-Path $PluginDir 'pack-vault.php'
$Readme = Join-Path $PluginDir 'readme.txt'
$PackageJson = Join-Path $PluginDir 'package.json'
$PackageLock = Join-Path $PluginDir 'package-lock.json'

# ---------------------------------------------------------------- 1. checks

Step "Checking release $Version"

foreach ($tool in @('git', 'npm') + $(if ($SkipSvn) { @() } else { @('svn') })) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { Fail "'$tool' is not installed or not on PATH." }
}

$m = [regex]::Match((Read-Utf8 $MainFile), "define\(\s*'PACKVAULT_VERSION',\s*'([^']+)'")
if (-not $m.Success) { Fail 'Could not read PACKVAULT_VERSION from pack-vault.php.' }
$Current = $m.Groups[1].Value
if ([version]$Version -lt [version]$Current) { Fail "New version $Version must be higher than the current version $Current." }
if ([version]$Version -eq [version]$Current) {
    # A previous run already bumped the files but stopped before publishing.
    # The tag checks below make sure this version was not released yet.
    Ok "Version is already $Version (continuing an earlier run)"
} else {
    Ok "Current version: $Current -> new version: $Version"
}

$readmeText = Read-Utf8 $Readme
if ($readmeText -notmatch "(?m)^= $([regex]::Escape($Version)) =\s*$") {
    Fail "readme.txt has no changelog entry for $Version. Add this under '== Changelog ==' first:`n`n= $Version =`n* What changed.`n"
}
Ok 'Changelog entry found in readme.txt'

$branch = (& git rev-parse --abbrev-ref HEAD).Trim()
if ($branch -ne 'main') { Fail "You are on branch '$branch'. Switch to 'main' first." }
if (& git tag --list "v$Version") { Fail "Git tag v$Version already exists." }
Ok 'Git branch main, tag is free'

if (-not $SkipSvn) {
    if (-not (Test-Path (Join-Path $SvnDir '.svn'))) { Fail "$SvnDir is not an SVN checkout." }
    Run svn update $SvnDir -q
    if (Test-Path (Join-Path $SvnDir "tags\$Version")) { Fail "SVN tag tags/$Version already exists. Tags can never be changed - pick a new version." }
    Ok 'SVN checkout up to date, tag is free'
}

# ---------------------------------------------------------------- 2. bump

Step 'Updating version numbers'
Replace-In $MainFile '^(\s*\*\s*Version:\s*)\S+' "`${1}$Version"
Replace-In $MainFile "(define\(\s*'PACKVAULT_VERSION',\s*')[^']+(')" "`${1}$Version`${2}"
Replace-In $Readme '^(Stable tag:\s*)\S+' "`${1}$Version"
Replace-In $PackageJson '("version":\s*")[^"]+(")' "`${1}$Version`${2}"
if (Test-Path $PackageLock) {
    # The first two "version" keys are the package itself (root + packages[""]).
    Replace-In $PackageLock '("version":\s*")[^"]+(")' "`${1}$Version`${2}" 2
}

# ---------------------------------------------------------------- 3. build & lint

Step 'Building assets (npm run build)'
Run npm.cmd run build --silent
Ok 'Build finished'

$php = Get-Command php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -First 1
if (-not $php) { $php = Get-ChildItem 'C:\laragon\bin\php\*\php.exe' -ErrorAction SilentlyContinue | Select-Object -Last 1 -ExpandProperty FullName }
if ($php) {
    Step 'Checking PHP syntax'
    $phpFiles = @(Get-ChildItem $PluginDir -Filter *.php -File) + @(Get-ChildItem (Join-Path $PluginDir 'includes') -Filter *.php -Recurse -File)
    foreach ($f in $phpFiles) {
        $out = & $php -l $f.FullName
        if ($LASTEXITCODE -ne 0) { Fail "PHP syntax error:`n$out" }
    }
    Ok "$($phpFiles.Count) PHP files OK"
} else {
    Write-Host '    PHP not found - skipped syntax check.' -ForegroundColor Yellow
}

# ---------------------------------------------------------------- 4. confirm

Step 'Ready to publish'
& git status --short
Write-Host ''
Write-Host "    This will commit everything above as '$Message'," -ForegroundColor Yellow
Write-Host "    push it to GitHub with tag v$Version$(if (-not $SkipSvn) { ', and publish it to WordPress.org' })." -ForegroundColor Yellow
Write-Host '    Test the plugin on your local site now if you have not yet.' -ForegroundColor Yellow
$answer = Read-Host '    Type "yes" to publish'
if ($answer -ne 'yes') {
    Write-Host ''
    Write-Host 'Stopped. Nothing was committed or uploaded. The version numbers are already bumped;' -ForegroundColor Yellow
    Write-Host "run '.\release.ps1 $Version' again when you are ready (or 'git checkout -- .' to undo)." -ForegroundColor Yellow
    exit 0
}

# ---------------------------------------------------------------- 5. GitHub

Step 'Committing and pushing to GitHub'
Run git add -A
Run git commit -q -m $Message
Run git push -q
Run git tag "v$Version"
$pushed = $false
for ($i = 1; $i -le 4 -and -not $pushed; $i++) {
    # GitHub sometimes rejects a tag pushed right after its commit; retry.
    & git push -q origin "v$Version"
    if ($LASTEXITCODE -eq 0) { $pushed = $true } else { Start-Sleep -Seconds 5 }
}
if ($pushed) {
    Ok "GitHub: main and v$Version pushed"
} else {
    Write-Host "    WARNING: tag v$Version was not pushed. Run 'git push origin v$Version' later." -ForegroundColor Yellow
}

if ($SkipSvn) { Step 'Done (SVN skipped)'; exit 0 }

# ---------------------------------------------------------------- 6. WordPress.org

Step 'Copying plugin into SVN trunk'
$trunk = Join-Path $SvnDir 'trunk'
$excludeDirs = @('.git', '.svn', '.github', '.qodo', '.vscode', '.idea', 'node_modules', '.wordpress-org')
$excludeFiles = @('.gitignore', '.gitattributes', '.distignore', 'PLAN.md', 'package-lock.json', '*.zip', 'release.ps1')
& robocopy $PluginDir $trunk /MIR /XD $excludeDirs /XF $excludeFiles /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { Fail "robocopy failed (exit code $LASTEXITCODE)." }

$added = 0; $removed = 0; $modified = 0
foreach ($line in (& svn status $trunk)) {
    if ($line.Length -lt 9) { continue }
    $state = $line.Substring(0, 1)
    $path = $line.Substring(8).Trim()
    switch ($state) {
        '?' { Run svn add -q $path; $added++ }
        '!' { Run svn rm -q $path; $removed++ }
        'M' { $modified++ }
    }
}
Ok "$added added, $modified modified, $removed removed"

Run svn cp -q $trunk (Join-Path $SvnDir "tags\$Version")
Ok "Created tags/$Version"

Step 'Committing to WordPress.org (enter your SVN password if asked)'
Run svn ci $SvnDir -m $Message --username $SvnUser

Step "Packvault $Version released"
Write-Host '    WordPress.org:  https://wordpress.org/plugins/pack-vault/' -ForegroundColor Green
Write-Host "    GitHub:         https://github.com/khalidbinahsan/pack-vault/releases/tag/v$Version" -ForegroundColor Green
Write-Host ''
Write-Host "    Remember: set `$sa_version = '$Version' in page-pack-vault.php on your website." -ForegroundColor Yellow
