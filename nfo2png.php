<?php
/**
 * NFO2PNG Online Script
 *
 * @author  NewEraCracker
 * @license MIT
 */

/**
 * Test if PHP installation contains the required extensions for this script
 *
 * @return boolean (Array with RGB components. False in case of failure)
 */
function testphp()
{
	global $errors;

	// Check GD
	if($gdinfo = @gd_info())
	{
		if(@(!($gdinfo['PNG Support'] && $gdinfo['FreeType Support'])))
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

	// Check Iconv
	if(!extension_loaded('iconv'))
	{
		$errors[] = 'PHP extension Iconv is not installed, unable to continue! Webmaster must fix this.';
		return false;
	}

	// Check for MultiByte String
	if(!extension_loaded('mbstring'))
	{
		$errors[] = 'PHP extension MultiByte String is not installed, unable to continue! Webmaster must fix this.';
		return false;
	}

	// YAY !
	return true;
}

/**
 * Parse color based on hexadecimal code (#RRGGBB)
 *
 * @param string (Hexadecimal Color Value)
 * @return array or boolean (Array with RGB components. False in case of failure)
 *
 * Based on implementation found at http://php.net/manual/en/function.hexdec.php#99478
 */
function parse_color($hexStr)
{
	// Get proper hex string
	$hexStr   = preg_replace("/[^0-9A-Fa-f]/", '', $hexStr);
	$rgbArray = array();
	if(strlen($hexStr) == 6)
	{
		// If a proper hex code, convert using bitwise operation. No overhead... faster
		$colorVal   = hexdec($hexStr);
		$rgbArray[] = 0xFF & ($colorVal >> 0x10);
		$rgbArray[] = 0xFF & ($colorVal >> 0x8);
		$rgbArray[] = 0xFF & $colorVal;
	}
	elseif(strlen($hexStr) == 3)
	{
		// If shorthand notation, need some string manipulations
		$rgbArray[] = hexdec(str_repeat(substr($hexStr, 0, 1), 2));
		$rgbArray[] = hexdec(str_repeat(substr($hexStr, 1, 1), 2));
		$rgbArray[] = hexdec(str_repeat(substr($hexStr, 2, 1), 2));
	}
	else
	{
		// Invalid hex color code
		return false;
	}

	// Return RGB array
	return $rgbArray;
}

/**
 * NFO2PNG Main function
 *
 * @param string (Path where NFO file is locaded)
 * @param string (File name of the NFO file)
 * @param string (Encoding. CP437, CP866 ...)
 * @param string (Background Color Hex RGB)
 * @param string (Text Color Hex RGB)
 * @return boolean (True on success. False on failure)
 */
function nfo2png_ttf($nfo_file, $nfo_name, $encoding = 'CP437', $bgcolor = 'FFFFFF', $txtcolor = '000000')
{
	global $errors;

	define('NFO_FONT_FILE', './assets/luconP.ttf');
	define('NFO_FONT_HEIGTH', 10);
	define('NFO_FONT_WIDTH', 8);
	define('NFO_FONT_LINE_SPACING', 3);
	define('NFO_FONT_LINE_HEIGTH', (NFO_FONT_HEIGTH + NFO_FONT_LINE_SPACING));

	// Deny files bigger than 1000 KiB (1024000 bytes)
	if(filesize($nfo_file) > 1024000)
	{
		$errors[] = 'File too big, try again with a smaller file!';
		return false;
	}

	// Load NFO file
	$nfo      = file($nfo_file);
	$nfo_name = pathinfo($nfo_name, PATHINFO_FILENAME) . '.png';

	// Initialize
	$xmax = 0;
	mb_internal_encoding('UTF-8');

	// Reformat each line
	foreach($nfo as &$line)
	{
		// Trim end-of-line
		$line = rtrim($line);

		// If line is not empty, convert it to UTF8
		if($line !== '')
		{
			$line = @iconv($encoding, 'UTF-8', $line);

			// We make a very strict check here, to be sure of all possibilities
			if($line === '' || $line === false || $line === null)
			{
				$errors[] = 'Invalid encoding detected.';
				return false;
			}
		}

		// Calculate maximum line length (UTF-8 ready)
		if($xmax < mb_strlen($line))
			$xmax = mb_strlen($line);
	}
	// Reference must be unset
	unset($line);

	// Size of image in pixels
	$xmax = (NFO_FONT_LINE_SPACING * 2) + (NFO_FONT_WIDTH * $xmax);
	$ymax = (NFO_FONT_LINE_SPACING * 3) + (NFO_FONT_LINE_HEIGTH * sizeof($nfo));

	// Deny images bigger than 10 million pixels
	if($xmax * $ymax > 10000000)
	{
		$errors[] = 'File too big, try again with a smaller file!';
		return false;
	}

	// Create image
	$im = imagecreatetruecolor($xmax, $ymax);

	// Allocate colors to image
	$bgcolor = parse_color($bgcolor);
	if(!$bgcolor)
	{
		@imagedestroy($im);
		$errors[] = 'Invalid background color.';
		return false;
	}
	$bgcolor = imagecolorallocate($im, $bgcolor[0], $bgcolor[1], $bgcolor[2]);

	$txtcolor = parse_color($txtcolor);
	if(!$txtcolor)
	{
		@imagedestroy($im);
		$errors[] = 'Invalid text color.';
		return false;
	}
	$txtcolor = imagecolorallocate($im, $txtcolor[0], $txtcolor[1], $txtcolor[2]);

	// Fill image background
	imagefilledrectangle($im, 0, 0, $xmax, $ymax, $bgcolor);

	// Add each line to image
	foreach($nfo as $y => $line)
	{
		// Y Axis
		$drawy = NFO_FONT_LINE_SPACING + (($y + 1) * NFO_FONT_LINE_HEIGTH);
		for($x = 0, $sz = mb_strlen($line); $x < $sz; $x++)
		{
			// X Axis
			$drawx = NFO_FONT_LINE_SPACING + ($x * NFO_FONT_WIDTH);

			// Char by char
			imagettftext($im, NFO_FONT_HEIGTH, 0, $drawx, $drawy, $txtcolor, NFO_FONT_FILE, mb_substr($line, $x, 1));
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

// Default code pages for NFO files
// Make sure your iconv version supports them
$codepages = array(
	437 => 'English',
	866 => 'Russian'
);

// Variable where errors will be saved
$errors = array();

// Run GD tests and check if we are processing a POST request
if(testphp() && $_SERVER['REQUEST_METHOD'] == 'POST')
{
	if(isset($_FILES['nfofile']['tmp_name'], $_FILES['nfofile']['name']))
	{
		// Inspect requested encoding
		reset($codepages);
		$encoding = isset($_POST['encoding']) ? intval($_POST['encoding']) : key($codepages);
		$encoding = isset($codepages[$encoding]) ? 'CP' . $encoding : 'CP' . key($codepages);

		// Colors
		$bgcolor  = isset($_POST['bgcolor']) ? $_POST['bgcolor'] : 'FFFFFF';
		$txtcolor = isset($_POST['txtcolor']) ? $_POST['txtcolor'] : '000000';

		// Process NFO 2 PNG action
		$retval = nfo2png_ttf($_FILES['nfofile']['tmp_name'], $_FILES['nfofile']['name'], $encoding, $bgcolor, $txtcolor);

		// Remove temporary file from our server
		unlink($_FILES['nfofile']['tmp_name']);

		if($retval)
		{
			// NFO 2 PNG success, bail out to avoid unexpected output
			exit();
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
		<script src="assets/jscolor/jscolor.js"></script>
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
			<p>Background Color: <input name="bgcolor" type="text" class="color" value="FFFFFF"></p>
			<p>Text Color: <input name="txtcolor" type="text" class="color" value="000000"></p>
			<p>Encoding: <select name="encoding"><?php
foreach($codepages as $number => $name)
{
	echo "<option value='{$number}'>{$name}</option>";
}
			?></select></p>
			<p><input type="submit" value="Convert to PNG" /></p>
			</form>
		</div>
	</body>
</html>