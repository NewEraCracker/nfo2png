<?php
/**
 * NFO2PNG Online Script
 *
 * @author  NewEraCracker
 * @license MIT
 *
 * With contributions by Crypt Xor
 */

// Directory where to host uploaded images
// Only enabled when setting is not empty
$hostDir = '';

// Default code pages for NFO files
// Make sure your iconv version supports them
$codepages = array(
	437 => 'English',
	866 => 'Russian'
);

// Variable where errors will be saved
$errors = array();

/**
 * Test if PHP installation contains the required extensions for this script
 *
 * @return boolean (True on success. False on failure)
 */
function testphp()
{
	global $errors;

	// Check GD
	if(function_exists('gd_info'))
	{
		$gdinfo = gd_info();
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
	// Do not accept arrays, as they will cause an array to string conversion notice.
	if(is_array($hexStr)) return false;

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

// Emulate SHA-256 on systems without Suhosin installed.
if(!function_exists('sha256'))
{
	/**
	 * Calculate a SHA-256 hash
	 *
	 * @param string (Message to be hashed)
	 * @param boolean (When set to TRUE, outputs raw binary data. FALSE outputs lowercase hexits)
	 * @return string or boolean (Calculated message digest. False on failure)
	 */
	function sha256($data, $raw_output = false)
	{
		global $errors;

		// Check for SHA-256 hashing support
		if(!function_exists('hash'))
		{
			$errors[] = 'PHP does not have support for SHA-256 hashing. Webmaster must install Suhosin or Hash extension.';
			return false;
		}

		// Hash and return
		return hash('sha256', $data, $raw_output);
	}
}

/**
 * Custom SHA256 function which returns a 52 chars hash (0-9, a-v)
 *
 * @param string (Message to be hashed)
 * @return string or boolean (Calculated message digest as lowercase. False on failure)
 *
 * Partially based on implementation found at http://www.revulo.com/blog/20080222.html
 */
function sha256_b32($str)
{
	// Do not accept arrays, as they will cause an array to string conversion notice.
	if(is_array($str)) return false;

	// Hash
	$str = sha256($str);

	// Verify
	if($str === false) return false;

	// Encode sha256 in base32hex
	for($res = '', $i = 0; $i < strlen($str); $i += 5)
	{
		$res .= str_pad(base_convert(substr($str, $i, 5), 16, 32), 4, '0', STR_PAD_LEFT);
	}
	return $res;
}

/**
 * NFO2PNG Main function
 *
 * @param string  (Path where NFO file is locaded)
 * @param string  (File name of the NFO file)
 * @param string  (Encoding. CP437, CP866 ...)
 * @param string  (Background Color Hex RGB)
 * @param string  (Text Color Hex RGB)
 * @param boolean (Create an hosted image)
 * @param string  (Directory where image will be hosted)
 * @return boolean (True on success. False on failure)
 */
function nfo2png_ttf($nfoFile, $nfoName, $encoding = 'CP437', $bgColor = 'FFFFFF', $txtColor = '000000', $hostImage = false, $hostDir = '')
{
	global $errors;

	define('NFO_FONT_FILE', './assets/luconP.ttf');
	define('NFO_FONT_HEIGTH', 10);
	define('NFO_FONT_WIDTH', 8);
	define('NFO_LINE_SPACING', 3);
	define('NFO_LINE_HEIGTH', (NFO_FONT_HEIGTH + NFO_LINE_SPACING));
	define('NFO_SIDES_SPACING', 5);

	// Deny files with invalid size
	if(filesize($nfoFile) <= 0)
	{
		$errors[] = 'File with invalid size, try again with another file!';
		return false;
	}

	// Deny files bigger than 1000 KiB (1024000 bytes)
	if(filesize($nfoFile) > 1024000)
	{
		$errors[] = 'File too big, try again with a smaller file!';
		return false;
	}

	// Initialize
	$nfo  = file($nfoFile);
	$xmax = 0;
	mb_internal_encoding('UTF-8');

	// Perform automatic UTF-8 BOM detection
	$utf8 = false;
	if(strpos($nfo[0], "\xEF\xBB\xBF") === 0)
	{
		$nfo[0] = substr($nfo[0], 3);
		$utf8   = true;
	}

	// Reformat each line
	foreach($nfo as &$line)
	{
		// Trim end-of-line
		$line = rtrim($line);

		// Convert it to UTF-8 if applicable
		if($line !== '' && !$utf8)
		{
			// Replace NBSP(s) (0xFF) with Space(s) (0x20)
			$line = str_replace("\xFF", "\x20", $line);

			// Perform conversion
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
	$xmax = (NFO_SIDES_SPACING * 2) + (NFO_FONT_WIDTH * $xmax);
	$ymax = (NFO_SIDES_SPACING * 2) + (NFO_LINE_HEIGTH * count($nfo));

	// Deny images bigger than 9 million pixels
	if($xmax * $ymax > 9000000)
	{
		$errors[] = 'File too big, try again with a smaller file!';
		return false;
	}

	// Create image
	$im = imagecreatetruecolor($xmax, $ymax);

	// Allocate colors to image
	$bgColor = parse_color($bgColor);
	if(!$bgColor)
	{
		@imagedestroy($im);
		$errors[] = 'Invalid background color.';
		return false;
	}
	$bgColor = imagecolorallocate($im, $bgColor[0], $bgColor[1], $bgColor[2]);

	$txtColor = parse_color($txtColor);
	if(!$txtColor)
	{
		@imagedestroy($im);
		$errors[] = 'Invalid text color.';
		return false;
	}
	$txtColor = imagecolorallocate($im, $txtColor[0], $txtColor[1], $txtColor[2]);

	// Fill image background
	imagefilledrectangle($im, 0, 0, $xmax, $ymax, $bgColor);

	// Add each line to image
	for($y = 0, $ycnt = count($nfo), $drawy = (NFO_SIDES_SPACING + NFO_LINE_HEIGTH); $y < $ycnt; $y++, $drawy += NFO_LINE_HEIGTH)
	{
		$drawx = NFO_SIDES_SPACING;
		imagettftext($im, NFO_FONT_HEIGTH, 0, $drawx, $drawy, $txtColor, NFO_FONT_FILE, $nfo[$y]);
	}

	// Start output buffering
	ob_start();

	// Generate image
	if(false === @imagepng($im))
	{
		imagedestroy($im);
		ob_end_clean();

		$errors[] = 'Image generation failed for unknown reasons.';
		return false;
	}

	// Capture and reset buffer
	$image = ob_get_clean();
	imagedestroy($im);

	if($hostImage && is_string($hostDir) && strlen($hostDir) > 0)
	{
		// Store file
		$fileName = sha256_b32($image);
		if($fileName === false) return false;

		$fileName = $hostDir . '/' . $fileName . '.png';
		if(false === @file_put_contents($fileName, $image))
		{
			$errors[] = 'It was not possible to write image into filesystem.';
			return false;
		}

		// Redirect user
		header("Location: {$fileName}", true, 303);
		return true;
	}
	else
	{
		// Process PNG download
		$fileName = pathinfo($nfoName, PATHINFO_FILENAME) . '.png';
		header('Content-Description: File Transfer');
		header('Content-Type: application/force-download');
		header("Content-Disposition: attachment; filename={$fileName}");
		echo $image;

		// Image successfully generated and passed to users browser
		return true;
	}

	return false;
}

// Run GD tests and check if we are processing a POST request
if(testphp() && $_SERVER['REQUEST_METHOD'] == 'POST')
{
	if(isset($_FILES['nfofile']['error']) && $_FILES['nfofile']['error'] != UPLOAD_ERR_OK)
	{
		$errors[] = 'There has been an error while uploading the file.';
	}
	elseif(isset($_FILES['nfofile']['tmp_name'], $_FILES['nfofile']['name']))
	{
		// Inspect requested encoding
		reset($codepages);
		$encoding = isset($_POST['encoding']) ? intval($_POST['encoding']) : key($codepages);
		$encoding = isset($codepages[$encoding]) ? 'CP' . $encoding : 'CP' . key($codepages);

		// Colors
		$bgColor  = isset($_POST['bgColor']) ? $_POST['bgColor'] : 'FFFFFF';
		$txtColor = isset($_POST['txtColor']) ? $_POST['txtColor'] : '000000';

		// Host an image
		$hostImage = isset($_POST['hostImage']) ? true : false;

		// Process NFO 2 PNG action
		$retval = nfo2png_ttf($_FILES['nfofile']['tmp_name'], $_FILES['nfofile']['name'], $encoding, $bgColor, $txtColor, $hostImage, $hostDir);

		// Remove temporary file from our server
		unlink($_FILES['nfofile']['tmp_name']);

		// If NFO 2 PNG is successful, bail out to avoid unexpected output
		if($retval) exit();
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
			<p>Background Color: <input name="bgColor" type="text" class="color" value="FFFFFF"></p>
			<p>Text Color: <input name="txtColor" type="text" class="color" value="000000"></p>
			<p>Encoding: <select name="encoding"><?php
foreach($codepages as $number => $name)
{
	echo "<option value='{$number}'>{$name}</option>";
}
			?></select></p>
<?php
if(is_string($hostDir) && strlen($hostDir) > 0)
{
?>
			<p><input type="checkbox" name="hostImage" />Create a image link?</p>
<?php
}
?>
			<p><input type="submit" value="Convert to PNG" /></p>
			</form>
		</div>
	</body>
</html>