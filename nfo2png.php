<?php
function testgd()
{
	global $errors;
	
	if(extension_loaded('gd'))
	{
		$gdinfo = @gd_info();
		if(@$gdinfo['PNG Support'] === true && @$gdinfo['FreeType Support'] === true)
		{
			return true;
		}
		else
		{
			$errors[] = 'Invalid GD configuration, unable to continue! Webmaster must fix this.';
			return false;
		}
	}
	else
	{
		$errors[] = 'PHP extension GD is not installed, unable to continue! Webmaster must fix this.';
		return false;
	}
}

function nfo2png_ttf($nfo_file, $nfo_name)
{
	define('NFO_FONT_FILE', './luconP.ttf');
	define('NFO_FONT_HEIGTH', 10);
	define('NFO_FONT_WIDTH', 8);
	define('NFO_FONT_LINE_SPACING', 3);
	define('NFO_FONT_LINE_HEIGTH', (NFO_FONT_HEIGTH + NFO_FONT_LINE_SPACING));
	
	// Deny files bigger than 1MB (1048576 bytes)
	if(filesize($nfo_file) > 1048576)
	{
		return false;
	}
	
	// Process nfo_name
	$nfo_name = pathinfo($nfo_name, PATHINFO_FILENAME);
	$nfo_name .= '.png';
	
	// Load NFO file
	$nfo = file($nfo_file);
	
	// Initialize x size
	$xmax = 0;
	
	// Reformat each line
	foreach($nfo as &$line)
	{
		// Trim end-of-line
		$line = rtrim($line);
		
		// Calculate maximum length
		if($xmax < strlen($line))
			$xmax = strlen($line);
	}
	// Reference must be unset
	unset($line);
	
	// Size of image in pixels
	$xmax = $xmax * NFO_FONT_WIDTH;
	$ymax = sizeof($nfo) * NFO_FONT_LINE_HEIGTH;
	
	// Deny images bigger than 20 million pixels
	if($xmax * $ymax > 20000000)
	{
		return false;
	}
	
	// Create the image
	$im = imagecreatetruecolor($xmax, $ymax);
	
	// Create some colors
	$white = imagecolorallocate($im, 255, 255, 255);
	$black = imagecolorallocate($im, 0, 0, 0);
	
	// Fill image background
	imagefilledrectangle($im, 0, 0, $xmax, $ymax, $white);
	
	// Add each line to image
	foreach($nfo as $y => $line)
	{
		$drawy = ($y + 1) * NFO_FONT_LINE_HEIGTH;
		for($x = 0, $sz = strlen($line); $x < $sz; $x++)
		{
			$drawx = $x * NFO_FONT_WIDTH;
			imagettftext($im, NFO_FONT_HEIGTH, 0, $drawx, $drawy, $black, NFO_FONT_FILE, $line[$x]);
		}
	}
	
	// Process PNG download
	header('Content-Description: File Transfer');
	header('Content-Type: application/force-download');
	header("Content-Disposition: attachment; filename={$nfo_name}");
	imagepng($im);
	imagedestroy($im);
	
	// Image successfully generated and passed to users browser
	return true;
}

// Variable where errors will be saved
$errors = array();

// Run GD tests and check if we are POSTing
if(testgd() && $_SERVER['REQUEST_METHOD'] == 'POST')
{
	
	if(isset($_FILES['nfofile']['tmp_name'], $_FILES['nfofile']['name']))
	{
		
		// Process NFO 2 PNG action
		$retval = nfo2png_ttf($_FILES['nfofile']['tmp_name'], $_FILES['nfofile']['name']);
		
		// Remove temporary file from our server
		unlink($_FILES['nfofile']['tmp_name']);
		
		if($retval)
		{
			// NFO 2 PNG success, bail out to avoid unexpected output
			exit();
		}
		else
		{
			$errors[] = 'File too big, try again with a smaller file!';
		}
	}
	else
	{
		$errors[] = 'No file has been uploaded.';
	}
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<title>NFO2PNG Online</title>
		<meta http-equiv="Content-type" content="text/html; charset=UTF-8" />
		<style type="text/css">
<!--
body { background-color:#000000; color:#FFFFFF; font-family:Verdana, Geneva, sans-serif; }
.error { color:#FF9900; }
.file { background-color:#000000; color:#FFFFFF; border:1px; margin:0px; }
-->
		</style>
	</head>
	<body>
		<div align="center">
			<h1>NFO2PNG ONLINE</h1>
			<br />
<?php
foreach($errors as $e)
{
	echo '<p class="error">' . htmlspecialchars($e) . '</p>';
}
?>
			<form enctype="multipart/form-data" action="" method="post">
			<p>File: <input name="nfofile" type="file" class="file" /></p>
			<p><input type="submit" value="Convert to PNG" /></p>
			</form>
		</div>
	</body>
</html>