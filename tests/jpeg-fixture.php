<?php
declare(strict_types=1);

function jpegFixture(string $label): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sw_' . preg_replace('/[^a-z0-9_-]/i', '', $label)
        . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $powerShellPath = str_replace("'", "''", $path);
    $script = 'Add-Type -AssemblyName System.Drawing; '
        . '$bitmap = New-Object System.Drawing.Bitmap 120,120; '
        . '$graphics = [System.Drawing.Graphics]::FromImage($bitmap); '
        . '$graphics.Clear([System.Drawing.Color]::SeaGreen); '
        . '$bitmap.Save(\'' . $powerShellPath . '\', [System.Drawing.Imaging.ImageFormat]::Jpeg); '
        . '$bitmap.Dispose(); $graphics.Dispose();';
    $encodedCommand = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    exec('powershell.exe -NoProfile -NonInteractive -EncodedCommand ' . $encodedCommand . ' 2>&1', $output, $status);
    if ($status !== 0 || !is_file($path)) {
        throw new RuntimeException('Could not create the JPEG test fixture: ' . implode(' ', $output));
    }
    return $path;
}
