param(
    [int]$Top = 30,
    [string]$Output = "docs/complexity-report.md"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-FileComplexity {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [string]$Layer
    )

    $lines = @(Get-Content -Path $Path).Count
    $content = Get-Content -Path $Path -Raw

    $branchRegex = '(?<!\w)(if|elseif|switch|case|catch|for|foreach|while)(?!\w)|&&|\|\|'
    $branches = ([regex]::Matches($content, $branchRegex)).Count
    $score = $lines + ($branches * 5)

    [PSCustomObject]@{
        Layer    = $Layer
        File     = ($Path -replace '\\', '/')
        Lines    = $lines
        Branches = $branches
        Score    = $score
    }
}

$targets = @(
    @{ Layer = "backend";  Root = "backend/app";  Pattern = "*.php" },
    @{ Layer = "frontend"; Root = "frontend/src"; Pattern = "*.js" },
    @{ Layer = "frontend"; Root = "frontend/src"; Pattern = "*.jsx" },
    @{ Layer = "realtime"; Root = "realtime/src"; Pattern = "*.js" }
)

$results = @()
foreach ($target in $targets) {
    if (-not (Test-Path -Path $target.Root)) {
        continue
    }

    $files = Get-ChildItem -Path $target.Root -Recurse -File -Filter $target.Pattern
    foreach ($file in $files) {
        $results += Get-FileComplexity -Path $file.FullName -Layer $target.Layer
    }
}

$topRows = $results | Sort-Object -Property Score -Descending | Select-Object -First $Top
$generatedAt = Get-Date -Format "yyyy-MM-dd HH:mm:ss zzz"

$md = @()
$md += "# Complexity Report"
$md += ""
$md += "Generated at: $generatedAt"
$md += ""
$md += 'Formula: `score = lines + (branches * 5)`'
$md += ""
$md += "| Rank | Layer | File | Lines | Branches | Score |"
$md += "| --- | --- | --- | ---: | ---: | ---: |"

$rank = 1
foreach ($row in $topRows) {
    $md += "| $rank | $($row.Layer) | $($row.File) | $($row.Lines) | $($row.Branches) | $($row.Score) |"
    $rank++
}

$outputDir = Split-Path -Path $Output -Parent
if (-not [string]::IsNullOrWhiteSpace($outputDir) -and -not (Test-Path -Path $outputDir)) {
    New-Item -Path $outputDir -ItemType Directory | Out-Null
}

Set-Content -Path $Output -Value ($md -join "`n")

Write-Host "Complexity report generated: $Output"
