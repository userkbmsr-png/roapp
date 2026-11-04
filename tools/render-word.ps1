param(
    [Parameter(Mandatory = $true)][string]$InputPath,
    [Parameter(Mandatory = $true)][string]$OutputPath
)
$ErrorActionPreference = 'Stop'
$word = $null
$document = $null
try {
    if (-not (Test-Path -LiteralPath $InputPath -PathType Leaf)) {
        throw 'Input DOCX does not exist.'
    }
    $outputDirectory = Split-Path -Parent $OutputPath
    if (-not (Test-Path -LiteralPath $outputDirectory -PathType Container)) {
        New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
    }
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $document = $word.Documents.Open($InputPath, $false, $true, $false)
    # WdExportFormat.wdExportFormatPDF = 17. Word's own renderer preserves template pagination and fonts.
    $document.ExportAsFixedFormat($OutputPath, 17)
    if (-not (Test-Path -LiteralPath $OutputPath -PathType Leaf)) {
        throw 'Word did not create the PDF.'
    }
    Write-Output 'PDF_READY'
    exit 0
}
catch {
    [Console]::Error.WriteLine('Word PDF render failed: ' + $_.Exception.Message)
    exit 1
}
finally {
    if ($null -ne $document) {
        try { $document.Close(0) } catch { }
        try { [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($document) } catch { }
    }
    if ($null -ne $word) {
        try { $word.Quit() } catch { }
        try { [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($word) } catch { }
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
