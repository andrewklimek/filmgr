<?php
/**
 * filmgr - a minimalist web-based file manager
 * github.com/andrewklimek/filmgr
 */

ini_set( 'log_errors', 1 );
ini_set( 'error_log', __DIR__ . '/filmgr.log' );
	
// Auth with login/password
$use_auth = true;

$conf_file = __DIR__ . '/.filmgr.conf';
$conf = @file_get_contents($conf_file);
$conf = $conf ? json_decode($conf) : (object)[];

$show_hidden_files = true;

$edit_files = true;

// Default timezone for date() and time() - http://php.net/manual/en/timezones.php
// $default_timezone = 'Etc/UTC'; // UTC

// Root path for file manager
$root_path = $_SERVER['DOCUMENT_ROOT'];

// Root url for links in file manager.Relative to $http_host. Variants: '', 'path/to/subfolder'
// Will not working if $root_path will be outside of server document root
$root_url = '';

// Server hostname. Can set manually if wrong
$http_host = $_SERVER['HTTP_HOST'];

// date() format for file modification date
$datetime_format = 'Y-m-d H:i';

// allowed upload file extensions
$upload_extensions = ''; // 'gif,png,jpg'

//Array of folders excluded from listing
$GLOBALS['exclude_folders'] = [];

//--- EDIT BELOW CAREFULLY OR DO NOT EDIT AT ALL

// if fm included
if (defined('FM_EMBED')) {
	$use_auth = false;
} else {
	@set_time_limit(600);

	if ( !empty($default_timezone) ) date_default_timezone_set($default_timezone);

	ini_set('default_charset', 'UTF-8');

	if (function_exists('mb_regex_encoding')) {
		mb_regex_encoding('UTF-8');
	}

	session_cache_limiter('');
	session_name('filemanager');
	session_start();
}

$is_https = isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1)
	|| isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https';

// clean and check $root_path
$root_path = rtrim($root_path, '\\/');
$root_path = str_replace('\\', '/', $root_path);
if (!@is_dir($root_path)) {
	echo "<h1>Root path \"{$root_path}\" not found!</h1>";
	exit;
}

// clean $root_url
$root_url = fm_clean_path($root_url);

// abs path for site
defined('FM_SHOW_HIDDEN') || define('FM_SHOW_HIDDEN', $show_hidden_files);
defined('FM_ROOT_PATH') || define('FM_ROOT_PATH', $root_path);
defined('FM_ROOT_URL') || define('FM_ROOT_URL', ($is_https ? 'https' : 'http') . '://' . $http_host . (!empty($root_url) ? '/' . $root_url : ''));
defined('FM_SELF_URL') || define('FM_SELF_URL', ($is_https ? 'https' : 'http') . '://' . $http_host . $_SERVER['PHP_SELF']);

// logout
if (isset($_GET['logout'])) {
	unset($_SESSION['logged']);
	fm_redirect(FM_SELF_URL);
}

// Auth
if ($use_auth) {
	if (isset($_SESSION['logged'], $conf->users->{$_SESSION['logged']})) {
		// Logged
	} elseif (isset($_POST['fm_usr'], $_POST['fm_pwd'])) {
	    // if no users, create them.
	    if (empty($conf->users)) {
            $conf->users = [ $_POST['fm_usr'] => md5($_POST['fm_pwd']) ];
            if ( false !== file_put_contents($conf_file, json_encode($conf)) ) {
                $_SESSION['logged'] = $_POST['fm_usr'];
    			fm_set_msg('User created');
    			fm_redirect(FM_SELF_URL . '?p=');
            }
    	}
    	
		// Logging In
		sleep(1);
		if (isset($conf->users->{$_POST['fm_usr']}) && md5($_POST['fm_pwd']) === $conf->users->{$_POST['fm_usr']}) {
			$_SESSION['logged'] = $_POST['fm_usr'];
			fm_redirect(FM_SELF_URL . '?p=');
		} else {
			unset($_SESSION['logged']);
			fm_set_msg("try again", 'error');
			fm_redirect(FM_SELF_URL);
		}
	} else {
		// Form
		unset($_SESSION['logged']);
		fm_header();
		fm_message();
		$value = empty($conf->users) ? "create login" : "log in";
		?>
		<div class=login-form>
			<form action="" method=post>
				<input autocapitalize=off id=fm_usr name=fm_usr placeholder=username required><br>
				<input type=password id=fm_pwd name=fm_pwd placeholder=password required><br>
				<input type=submit value="<?php echo $value ?>">
			</form>
		</div>
	</html>
		<?php
		exit;
	}
}

defined('FM_EXTENSION') || define('FM_EXTENSION', $upload_extensions);

// always use ?p=
if (!isset($_GET['p'])) {
	fm_redirect(FM_SELF_URL . '?p=');
}

// get path
$p = isset($_GET['p']) ? $_GET['p'] : (isset($_POST['p']) ? $_POST['p'] : '');

define('FM_PATH', fm_clean_path($p));
define('FM_USE_AUTH', $use_auth);
define('FM_EDIT_FILE', $edit_files);
defined('FM_DATETIME_FORMAT') || define('FM_DATETIME_FORMAT', $datetime_format);

unset($p, $use_auth);

/*************************** ACTIONS ***************************/

//AJAX Request
if (isset($_POST['ajax'])) {
		
	//Save File
	if(isset($_POST['data'])) {
		error_log(var_export($_REQUEST,true));
		$path = FM_ROOT_PATH;
		if (FM_PATH != '') $path .= '/' . FM_PATH;
		$file_path = $path . '/' . fm_clean_path($_GET['f']);
		$result = file_put_contents( $file_path, $_POST['data'], LOCK_EX );
		if ( $result === false ) {
			echo "fail~!! failed to save !!";
		} else {
			echo "ok~Saved";
		}
	}

	//backup files
	if(isset($_POST['type']) && $_POST['type']=="backup") {
		$file = $_POST['file'];
		$path = $_POST['path'];
		$date = date("dMy-His");
		$newFile = $file.'-'.$date.'.bak';
		copy($path.'/'.$file, $path.'/'.$newFile) or die("Unable to backup");
		echo "Backup $newFile Created";
	}

	exit;
}

// Delete file / folder
if (isset($_GET['del'])) {
	$del = $_GET['del'];
	$del = fm_clean_path($del);
	$del = str_replace('/', '', $del);
	if ($del != '' && $del != '..' && $del != '.') {
		$path = FM_ROOT_PATH;
		if (FM_PATH != '') {
			$path .= '/' . FM_PATH;
		}
		$is_dir = is_dir($path . '/' . $del);
		if (fm_rdelete($path . '/' . $del)) {
			$msg = $is_dir ? 'Folder <b>%s</b> deleted' : 'File <b>%s</b> deleted';
			fm_set_msg(sprintf($msg, fm_enc($del)));
		} else {
			$msg = $is_dir ? 'Folder <b>%s</b> not deleted' : 'File <b>%s</b> not deleted';
			fm_set_msg(sprintf($msg, fm_enc($del)), 'error');
		}
	} else {
		fm_set_msg('Wrong file or folder name', 'error');
	}
	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Create folder
if (isset($_GET['new']) && isset($_GET['type'])) {
	$new = strip_tags($_GET['new']);
	$type = $_GET['type'];
	$new = fm_clean_path($new);
	$new = str_replace('/', '', $new);
	if ($new != '' && $new != '..' && $new != '.') {
		$path = FM_ROOT_PATH;
		if (FM_PATH != '') {
			$path .= '/' . FM_PATH;
		}
		if($_GET['type']=="file") {
			if(!file_exists($path . '/' . $new)) {
				@fopen($path . '/' . $new, 'w') or die('Cannot open file:  '.$new);
				fm_set_msg(sprintf('File <b>%s</b> created', fm_enc($new)));
			} else {
				fm_set_msg(sprintf('File <b>%s</b> already exists', fm_enc($new)), 'alert');
			}
		} else {
			if (fm_mkdir($path . '/' . $new, false) === true) {
				fm_set_msg(sprintf('Folder <b>%s</b> created', $new));
			} elseif (fm_mkdir($path . '/' . $new, false) === $path . '/' . $new) {
				fm_set_msg(sprintf('Folder <b>%s</b> already exists', fm_enc($new)), 'alert');
			} else {
				fm_set_msg(sprintf('Folder <b>%s</b> not created', fm_enc($new)), 'error');
			}
		}
	} else {
		fm_set_msg('Wrong folder name', 'error');
	}
	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Copy folder / file
if (isset($_GET['copy'], $_GET['finish'])) {
	// from
	$copy = $_GET['copy'];
	$copy = fm_clean_path($copy);
	// empty path
	if ($copy == '') {
		fm_set_msg('Source path not defined', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}
	// abs path from
	$from = FM_ROOT_PATH . '/' . $copy;
	// abs path to
	$dest = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$dest .= '/' . FM_PATH;
	}
	$dest .= '/' . basename($from);
	// move?
	$move = isset($_GET['move']);
	// copy/move
	if ($from != $dest) {
		$msg_from = trim(FM_PATH . '/' . basename($from), '/');
		if ($move) {
			$rename = fm_rename($from, $dest);
			if ($rename) {
				fm_set_msg(sprintf('Moved from <b>%s</b> to <b>%s</b>', fm_enc($copy), fm_enc($msg_from)));
			} elseif ($rename === null) {
				fm_set_msg('File or folder with this path already exists', 'alert');
			} else {
				fm_set_msg(sprintf('Error while moving from <b>%s</b> to <b>%s</b>', fm_enc($copy), fm_enc($msg_from)), 'error');
			}
		} else {
			if (fm_rcopy($from, $dest)) {
				fm_set_msg(sprintf('Copyied from <b>%s</b> to <b>%s</b>', fm_enc($copy), fm_enc($msg_from)));
			} else {
				fm_set_msg(sprintf('Error while copying from <b>%s</b> to <b>%s</b>', fm_enc($copy), fm_enc($msg_from)), 'error');
			}
		}
	} else {
		fm_set_msg('Paths must be not equal', 'alert');
	}
	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Mass copy files/ folders
if (isset($_POST['file'], $_POST['copy_to'], $_POST['finish'])) {
	// from
	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}
	// to
	$copy_to_path = FM_ROOT_PATH;
	$copy_to = fm_clean_path($_POST['copy_to']);
	if ($copy_to != '') {
		$copy_to_path .= '/' . $copy_to;
	}
	if ($path == $copy_to_path) {
		fm_set_msg('Paths must be not equal', 'alert');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}
	if (!is_dir($copy_to_path)) {
		if (!fm_mkdir($copy_to_path, true)) {
			fm_set_msg('Unable to create destination folder', 'error');
			fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
		}
	}
	// move?
	$move = isset($_POST['move']);
	// copy/move
	$errors = 0;
	$files = $_POST['file'];
	if (is_array($files) && count($files)) {
		foreach ($files as $f) {
			if ($f != '') {
				// abs path from
				$from = $path . '/' . $f;
				// abs path to
				$dest = $copy_to_path . '/' . $f;
				// do
				if ($move) {
					$rename = fm_rename($from, $dest);
					if ($rename === false) {
						$errors++;
					}
				} else {
					if (!fm_rcopy($from, $dest)) {
						$errors++;
					}
				}
			}
		}
		if ($errors == 0) {
			$msg = $move ? 'Selected files and folders moved' : 'Selected files and folders copied';
			fm_set_msg($msg);
		} else {
			$msg = $move ? 'Error while moving items' : 'Error while copying items';
			fm_set_msg($msg, 'error');
		}
	} else {
		fm_set_msg('Nothing selected', 'alert');
	}
	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Rename
if (isset($_GET['ren'], $_GET['to'])) {
	// old name
	$old = $_GET['ren'];
	$old = fm_clean_path($old);
	$old = str_replace('/', '', $old);
	// new name
	$new = $_GET['to'];
	$new = fm_clean_path($new);
	$new = str_replace('/', '', $new);
	// path
	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}
	// rename
	if ($old != '' && $new != '') {
		if (fm_rename($path . '/' . $old, $path . '/' . $new)) {
			fm_set_msg(sprintf('Renamed from <b>%s</b> to <b>%s</b>', fm_enc($old), fm_enc($new)));
		} else {
			fm_set_msg(sprintf('Error while renaming from <b>%s</b> to <b>%s</b>', fm_enc($old), fm_enc($new)), 'error');
		}
	} else {
		fm_set_msg('Names not set', 'error');
	}
	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Download
if (isset($_GET['dl'])) {
	$dl = $_GET['dl'];
	$dl = fm_clean_path($dl);
	$dl = str_replace('/', '', $dl);
	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}
	if ($dl != '' && is_file($path . '/' . $dl)) {
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . basename($path . '/' . $dl) . '"');
		header('Content-Transfer-Encoding: binary');
		header('Connection: Keep-Alive');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Content-Length: ' . filesize($path . '/' . $dl));
		readfile($path . '/' . $dl);
		exit;
	} else {
		fm_set_msg('File not found', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}
}

// Upload
if (isset($_POST['upl'])) {
	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}

	$errors = 0;
	$uploads = 0;
	$total = count($_FILES['upload']['name']);
	  $allowed = (FM_EXTENSION) ? explode(',', FM_EXTENSION) : false;

	for ($i = 0; $i < $total; $i++) {
		$filename = $_FILES['upload']['name'][$i];
		$tmp_name = $_FILES['upload']['tmp_name'][$i];
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		$isFileAllowed = ($allowed) ? in_array($ext,$allowed) : true;
		if (empty($_FILES['upload']['error'][$i]) && !empty($tmp_name) && $tmp_name != 'none' && $isFileAllowed) {
			if (move_uploaded_file($tmp_name, $path . '/' . $_FILES['upload']['name'][$i])) {
				$uploads++;
			} else {
				$errors++;
			}
		}
	}

	if ($errors == 0 && $uploads > 0) {
		fm_set_msg(sprintf('All files uploaded to <b>%s</b>', fm_enc($path)));
	} elseif ($errors == 0 && $uploads == 0) {
		fm_set_msg('Nothing uploaded', 'alert');
	} else {
		fm_set_msg(sprintf('Error while uploading files. Uploaded files: %s', $uploads), 'error');
	}
	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Mass deleting
if (isset($_POST['group'], $_POST['delete'])) {
	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}

	$errors = 0;
	$files = $_POST['file'];
	if (is_array($files) && count($files)) {
		foreach ($files as $f) {
			if ($f != '') {
				$new_path = $path . '/' . $f;
				if (!fm_rdelete($new_path)) {
					$errors++;
				}
			}
		}
		if ($errors == 0) {
			fm_set_msg('Selected files and folder deleted');
		} else {
			fm_set_msg('Error while deleting items', 'error');
		}
	} else {
		fm_set_msg('Nothing selected', 'alert');
	}

	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Pack files
if (isset($_POST['group'], $_POST['zip'])) {
	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}

	if (!class_exists('ZipArchive')) {
		fm_set_msg('Operations with archives are not available', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}

	$files = $_POST['file'];
	if (!empty($files)) {
		chdir($path);

		if (count($files) == 1) {
			$one_file = reset($files);
			$one_file = basename($one_file);
			$zipname = $one_file . '_' . date('ymd_His') . '.zip';
		} else {
			$zipname = 'archive_' . date('ymd_His') . '.zip';
		}

		$zipper = new FM_Zipper();
		$res = $zipper->create($zipname, $files);

		if ($res) {
			fm_set_msg(sprintf('Archive <b>%s</b> created', fm_enc($zipname)));
		} else {
			fm_set_msg('Archive not created', 'error');
		}
	} else {
		fm_set_msg('Nothing selected', 'alert');
	}

	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Unpack
if (isset($_GET['unzip'])) {
	$unzip = $_GET['unzip'];
	$unzip = fm_clean_path($unzip);
	$unzip = str_replace('/', '', $unzip);

	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}

	if (!class_exists('ZipArchive')) {
		fm_set_msg('Operations with archives are not available', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}

	if ($unzip != '' && is_file($path . '/' . $unzip)) {
		$zip_path = $path . '/' . $unzip;

		//to folder
		$tofolder = '';
		if (isset($_GET['tofolder'])) {
			$tofolder = pathinfo($zip_path, PATHINFO_FILENAME);
			if (fm_mkdir($path . '/' . $tofolder, true)) {
				$path .= '/' . $tofolder;
			}
		}

		$zipper = new FM_Zipper();
		$res = $zipper->unzip($zip_path, $path);

		if ($res) {
			fm_set_msg('Archive unpacked');
		} else {
			fm_set_msg('Archive not unpacked', 'error');
		}

	} else {
		fm_set_msg('File not found', 'error');
	}
	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Change Perms
if (isset($_POST['chmod'])) {
	$path = FM_ROOT_PATH;
	if (FM_PATH != '') {
		$path .= '/' . FM_PATH;
	}

	$file = $_POST['chmod'];
	$file = fm_clean_path($file);
	$file = str_replace('/', '', $file);
	if ($file == '' || (!is_file($path . '/' . $file) && !is_dir($path . '/' . $file))) {
		fm_set_msg('File not found', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}

	$mode = 0;
	if (!empty($_POST['ur'])) $mode |= 0400;
	if (!empty($_POST['uw'])) $mode |= 0200;
	if (!empty($_POST['ux'])) $mode |= 0100;
	if (!empty($_POST['gr'])) $mode |= 0040;
	if (!empty($_POST['gw'])) $mode |= 0020;
	if (!empty($_POST['gx'])) $mode |= 0010;
	if (!empty($_POST['or'])) $mode |= 0004;
	if (!empty($_POST['ow'])) $mode |= 0002;
	if (!empty($_POST['ox'])) $mode |= 0001;

	if (@chmod($path . '/' . $file, $mode)) {
		fm_set_msg('Permissions changed');
	} else {
		fm_set_msg('Permissions not changed', 'error');
	}

	fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

/*************************** /ACTIONS ***************************/

// get current path
$path = FM_ROOT_PATH;
if (FM_PATH != '') {
	$path .= '/' . FM_PATH;
}

// check path
if (!is_dir($path)) {
	fm_redirect(FM_SELF_URL . '?p=');
}

// get parent folder
$parent = fm_get_parent_path(FM_PATH);

$objects = is_readable($path) ? scandir($path) : [];
$folders = [];
$files = [];
if (is_array($objects)) {
	foreach ($objects as $file) {
		if ($file == '.' || $file == '..' && in_array($file, $GLOBALS['exclude_folders'])) {
			continue;
		}
		if (!FM_SHOW_HIDDEN && substr($file, 0, 1) === '.') {
			continue;
		}
		$new_path = $path . '/' . $file;
		if (is_file($new_path)) {
			$files[] = $file;
		} elseif (is_dir($new_path) && $file != '.' && $file != '..' && !in_array($file, $GLOBALS['exclude_folders'])) {
			$folders[] = $file;
		}
	}
}

if (!empty($files)) {
	natcasesort($files);
}
if (!empty($folders)) {
	natcasesort($folders);
}

// upload form
if (isset($_GET['upload'])) {

	fm_header();
	fm_header_menu();
	fm_path();
	
	?>
	<div>
		<p><b>Uploading files</b></p>
		<p>Destination folder: <?php echo fm_enc(FM_ROOT_PATH . '/' . FM_PATH) ?></p>
		<form action="" method=post enctype="multipart/form-data">
			<input type=hidden name=p value="<?php echo fm_enc(FM_PATH) ?>">
			<input type=hidden name=upl value=1>
			<input type=file name=upload[]><br>
			<input type=file name=upload[]><br>
			<input type=file name=upload[]><br>
			<input type=file name=upload[]><br>
			<input type=file name=upload[]><br>
			<br>
			<p>
				<button type=submit class=btn>Upload</button> &nbsp;
				<b><a href="?p=<?php echo urlencode(FM_PATH) ?>">Cancel</a></b>
			</p>
		</form>
	</div>
	<?php
	fm_footer();
	exit;
}

// copy form POST
if (isset($_POST['copy'])) {
	$copy_files = $_POST['file'];
	if (!is_array($copy_files) || empty($copy_files)) {
		fm_set_msg('Nothing selected', 'alert');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}

	fm_header();
	fm_header_menu();
	fm_path();
	?>
	<div>
		<p><b>Copying</b></p>
		<form action="" method=post>
			<input type=hidden name=p value="<?php echo fm_enc(FM_PATH) ?>">
			<input type=hidden name=finish value=1>
			<?php
			foreach ($copy_files as $cf) {
				echo '<input type=hidden name=file[] value="' . fm_enc($cf) . '">' . PHP_EOL;
			}
			?>
			<p>Files: <b><?php echo implode('</b>, <b>', $copy_files) ?></b></p>
			<p>Source folder: <?php echo fm_enc(FM_ROOT_PATH . '/' . FM_PATH) ?><br>
				<label for=inp_copy_to>Destination folder:</label>
				<?php echo FM_ROOT_PATH ?>/<input type=text name=copy_to id=inp_copy_to value="<?php echo fm_enc(FM_PATH) ?>">
			</p>
			<p><label><input type=checkbox name=move value=1>Move</label></p>
			<p>
				<button type=submit class=btn>Copy</button> &nbsp;
				<b><a href="?p=<?php echo urlencode(FM_PATH) ?>">Cancel</a></b>
			</p>
		</form>
	</div>
	<?php
	fm_footer();
	exit;
}

// copy form
if (isset($_GET['copy']) && !isset($_GET['finish'])) {
	$copy = $_GET['copy'];
	$copy = fm_clean_path($copy);
	if ($copy == '' || !file_exists(FM_ROOT_PATH . '/' . $copy)) {
		fm_set_msg('File not found', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}

	fm_header();
	fm_header_menu();
	fm_path();
	?>
	<div>
		<p><b>Copying</b></p>
		<p>
			Source path: <?php echo fm_enc(FM_ROOT_PATH . '/' . $copy) ?><br>
			Destination folder: <?php echo fm_enc(FM_ROOT_PATH . '/' . FM_PATH) ?>
		</p>
		<p>
			<b><a href="?p=<?php echo urlencode(FM_PATH) ?>&copy=<?php echo urlencode($copy) ?>&finish=1">Copy</a></b> &nbsp;
			<b><a href="?p=<?php echo urlencode(FM_PATH) ?>&copy=<?php echo urlencode($copy) ?>&finish=1&move=1">Move</a></b> &nbsp;
			<b><a href="?p=<?php echo urlencode(FM_PATH) ?>">Cancel</a></b>
		</p>
		<p><i>Select folder</i></p>
		<ul class=folders>
			<?php
			if ($parent !== false) {
				?>
				<li><a href="?p=<?php echo urlencode($parent) ?>&copy=<?php echo urlencode($copy) ?>">..</a></li>
			<?php
			}
			foreach ($folders as $f) {
				?>
				<li><a href="?p=<?php echo urlencode(trim(FM_PATH . '/' . $f, '/')) ?>&copy=<?php echo urlencode($copy) ?>"><?php echo $f ?></a></li>
			<?php
			}
			?>
		</ul>
	</div>
	<?php
	fm_footer();
	exit;
}

if (isset($_GET['pad'])) {
	fm_header("scratch pad");
	echo "<nav class='main-nav right'>";
	echo "<span id=logo>filmgr</span>";
	$typical_exts = [ 'php' => 'php', 'js' => 'javascript', 'css' => 'css', 'html' => 'html' ];
	foreach( $typical_exts as $ext => $mode ) {
		echo "<a onclick=\"editor.session.setMode('ace/mode/{$mode}')\">$ext</a> ";
	}
	echo "</nav>";
	echo "<pre id=editor contenteditable></pre>";
	fm_load_ace();
	fm_footer();
	exit;
}

if (isset($_GET['f'])) {

	$file = fm_clean_path($_GET['f']);

	if ($file == '' || !is_file($path . '/' . $file)) {
		fm_set_msg('File not found', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}

	fm_header($file);
	
	// fm_path();

	$file_uri = (FM_PATH != '' ? '/' . FM_PATH : '') . '/' . $file;
	$file_url = FM_ROOT_URL . $file_uri;
	$file_path = $path . '/' . $file;

	$filetype = fm_get_file_type($file_path);

	if ( $filetype == "text" ) {// edit text files
	
		//Save File
		if(isset($_POST['savedata'])) {
			$result = file_put_contents( $file_path, $_POST['savedata'], LOCK_EX );
			if ( $result === false ) {
				fm_set_msg('File Not Saved', 'error');
			} else {
				fm_set_msg('File Saved Successfully', 'alert');
			}
		}

		$menu_items = "<a onclick=\"backup('". urlencode($path) ."','". urlencode($file) ."')\">Backup</a> ";
		echo $file_uri;
		if ( $file_uri == $_SERVER['PHP_SELF'] ) {
			$menu_items .= "<a>Can’t edit self!</a>";
		} else {
			$menu_items .= "<a id=save onclick=save()>Save</a>";
		}

		fm_header_menu("edit", $menu_items);

		$content = file_get_contents($file_path);

		?>
		<pre id=editor contenteditable><?php echo htmlspecialchars($content); ?></pre>
		<?php
		fm_load_ace();
	}
	else
	{
		fm_header_menu("view");
		?>
		<div>
			<p>
				<?php
				// echo "File size: ". fm_get_filesize(filesize($file_path)) ."<br>";
				// echo "MIME-type: ". fm_get_mime_type($file_path) ."<br>";
				
				// ZIP info
				if ($filetype == 'archive') {
					$filenames = fm_get_zif_info($file_path);
					if ($filenames) {
						$total_files = 0;
						$total_comp = 0;
						$total_uncomp = 0;
						foreach ($filenames as $fn) {
							if (!$fn['folder']) {
								$total_files++;
							}
							$total_comp += $fn['compressed_size'];
							$total_uncomp += $fn['filesize'];
						}
						?>
						Files in archive: <?php echo $total_files ?><br>
						Total size: <?php echo fm_get_filesize($total_uncomp) ?><br>
						Size in archive: <?php echo fm_get_filesize($total_comp) ?><br>
						Compression: <?php echo round(($total_comp / $total_uncomp) * 100) ?>%<br>
						<?php
					}
				}
				// Image info
				if ($filetype == 'image' && $image_size = getimagesize($file_path) ) {
					echo "$image_size[0] × $image_size[1] <br>";
				}
				?>
			</p>
			<p>
				<b><a href="?p=<?php echo urlencode(FM_PATH) ?>&dl=<?php echo urlencode($file) ?>">Download</a></b> &nbsp;
				<b><a href="<?php echo fm_enc($file_url) ?>" target=_blank>Open</a></b> &nbsp;
				<?php
				// ZIP actions
				if ($filetype == 'archive' && $filenames) {
					$zip_name = pathinfo($file_path, PATHINFO_FILENAME);
					?>
					<b><a href="?p=<?php echo urlencode(FM_PATH) ?>&unzip=<?php echo urlencode($file) ?>">Unzip</a></b> &nbsp;
					<b><a href="?p=<?php echo urlencode(FM_PATH) ?>&unzip=<?php echo urlencode($file) ?>&tofolder=1" title="UnZip to <?php echo fm_enc($zip_name) ?>">Unzip to folder</a></b> &nbsp;
					<?php
				} ?>
				<b><a href="?p=<?php echo urlencode(FM_PATH) ?>">Back</a></b>
			</p>
			<?php
			if ($filetype == 'archive') {
				// ZIP content
				if ($filenames) {
					echo '<code>';
					foreach ($filenames as $fn) {
						if ($fn['folder']) {
							echo '<b>' . fm_enc($fn['name']) . '</b><br>';
						} else {
							echo $fn['name'] . ' (' . fm_get_filesize($fn['filesize']) . ')<br>';
						}
					}
					echo '</code>';
				} else {
					echo '<p>Error while fetching archive info</p>';
				}
			} elseif ($filetype == 'image') {
				// Image content
				$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
				if (in_array($ext, ['gif', 'jpg', 'jpeg', 'png', 'bmp', 'ico', 'svg', 'webp', 'avif'])) {
					echo '<img src="' . fm_enc($file_url) . '" class=preview-img>';
				}
			} elseif ($filetype == 'audio') {
				// Audio content
				echo '<audio src="' . fm_enc($file_url) . '" controls preload=metadata></audio>';
			} elseif ($filetype == 'video') {
				// Video content
				echo '<div class=preview-video><video src="' . fm_enc($file_url) . '" width=640 height=360 controls preload=metadata></video></div>';
			}
			?>
		</div>
		<?php
	}

	fm_footer();
	exit;
}

// chmod
if (isset($_GET['chmod'])) {
	$file = $_GET['chmod'];
	$file = fm_clean_path($file);
	$file = str_replace('/', '', $file);
	if ($file == '' || (!is_file($path . '/' . $file) && !is_dir($path . '/' . $file))) {
		fm_set_msg('File not found', 'error');
		fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
	}

	fm_header();
	fm_header_menu();
	fm_path();

	$file_url = FM_ROOT_URL . (FM_PATH != '' ? '/' . FM_PATH : '') . '/' . $file;
	$file_path = $path . '/' . $file;

	$mode = fileperms($path . '/' . $file);

	?>
	<div>
		<p><b><?php echo 'Change Permissions'; ?></b></p>
		<p>
			<?php echo 'Full path:'; ?> <?php echo $file_path ?><br>
		</p>
		<form action="" method=post>
			<input type=hidden name=p value="<?php echo fm_enc(FM_PATH) ?>">
			<input type=hidden name=chmod value="<?php echo fm_enc($file) ?>">

			<table class=center>
				<tr>
					<td>
					<td>Owner
					<td>Group
					<td>Other
				<tr>
					<th class=right>Read
					<td><label><input type=checkbox name=ur value=1<?php echo ($mode & 00400) ? ' checked' : '' ?>></label>
					<td><label><input type=checkbox name=gr value=1<?php echo ($mode & 00040) ? ' checked' : '' ?>></label>
					<td><label><input type=checkbox name=or value=1<?php echo ($mode & 00004) ? ' checked' : '' ?>></label>
				<tr>
					<th class=right>Write
					<td><label><input type=checkbox name=uw value=1<?php echo ($mode & 00200) ? ' checked' : '' ?>></label>
					<td><label><input type=checkbox name=gw value=1<?php echo ($mode & 00020) ? ' checked' : '' ?>></label>
					<td><label><input type=checkbox name=ow value=1<?php echo ($mode & 00002) ? ' checked' : '' ?>></label>
				<tr>
					<th class=right>Execute
					<td><label><input type=checkbox name=ux value=1<?php echo ($mode & 00100) ? ' checked' : '' ?>></label>
					<td><label><input type=checkbox name=gx value=1<?php echo ($mode & 00010) ? ' checked' : '' ?>></label>
					<td><label><input type=checkbox name=ox value=1<?php echo ($mode & 00001) ? ' checked' : '' ?>></label>
			</table>
			<p>
				<button type=submit class=btn>Change</button> &nbsp;
				<b><a href="?p=<?php echo urlencode(FM_PATH) ?>">Cancel</a></b>
			</p>

		</form>

	</div>
	<?php
	fm_footer();
	exit;
}

//--- FILEMANAGER MAIN

fm_header( array_reverse(explode('/',$path))[0] );
fm_header_menu('main');
fm_message();
fm_path();

?>
<form action="" method=post>
<input type=hidden name=p value="<?php echo fm_enc(FM_PATH) ?>">
<input type=hidden name=group value=1>
<table id=main-table>
<tr class=th>
<td style=width:14px><input type=checkbox title="Select All" onclick="checkbox(this.checked)">
<td style=width:14px>
<td><?php echo count($files) + count($folders) ?> items
<td class=right style=width:6em>Size
<td style=width:9em>Modified
<td style=width:3em>Perms<td style=width:17em>Owner
<!--<td style="width:17em">Actions</div>-->
<?php
foreach ($folders as $f) {
	$is_link = is_link($path . '/' . $f);
	$modif = date(FM_DATETIME_FORMAT, filemtime($path . '/' . $f));
	$perms = substr(decoct(fileperms($path . '/' . $f)), -4);
	if (function_exists('posix_getpwuid') && function_exists('posix_getgrgid')) {
		$owner = posix_getpwuid(fileowner($path . '/' . $f));
		$group = posix_getgrgid(filegroup($path . '/' . $f));
		$own = $owner['name'] .':'. $group['name'];
	} else {
		$own = '';
	}
	?>
	<tr class=folder>
	<td><input type=checkbox name=file[] value="<?php echo fm_enc($f) ?>">
	<td class=itemmenu><span class=item-menu-btn onclick="this.nextElementSibling.firstElementChild.focus()">…</span>
	<div class="inline-actions">
	<a tabindex=0 href="<?php echo fm_enc(FM_ROOT_URL . (FM_PATH != '' ? '/' . FM_PATH : '') . '/' . $f . '/') ?>" target=_blank>Link</a>
	<a tabindex=0 href="?p=<?php echo urlencode(FM_PATH) ?>&del=<?php echo urlencode($f) ?>" onclick="return confirm('Delete folder?');">Delete</a>
	<a tabindex=0 href="javascript:rename('<?php echo fm_enc(FM_PATH) ?>', '<?php echo fm_enc($f) ?>')">Rename</a>
	<a tabindex=0 href="?p=&copy=<?php echo urlencode(trim(FM_PATH . '/' . $f, '/')) ?>">Copy</a>
	</div>
	<td class=filename><a href="?p=<?php echo urlencode(trim(FM_PATH . '/' . $f, '/')) ?>"><?php echo $f ?> /</a><?php echo ($is_link ? ' &rarr; <i>' . readlink($path . '/' . $f) . '</i>' : '') ?>
	<td><td><?php echo $modif ?>
	<td><a title="Change Permissions" href="?p=<?php echo urlencode(FM_PATH) ?>&chmod=<?php echo urlencode($f) ?>"><?php echo $perms ?></a>
	<td><?php echo $own ?>
	<?php
	flush();
}

foreach ($files as $f) {
	$is_link = is_link($path . '/' . $f);
	$modif = date(FM_DATETIME_FORMAT, filemtime($path . '/' . $f));
	$filesize_raw = filesize($path . '/' . $f);
	$filesize = fm_get_filesize($filesize_raw);
	$filelink = '?p=' . urlencode(FM_PATH) . '&f=' . urlencode($f);
	$perms = substr(decoct(fileperms($path . '/' . $f)), -4);
	if (function_exists('posix_getpwuid') && function_exists('posix_getgrgid')) {
		$owner = posix_getpwuid(fileowner($path . '/' . $f));
		$group = posix_getgrgid(filegroup($path . '/' . $f));
		$own = $owner['name'] .':'. $group['name'];
	} else {
		$own = '';
	}
	?>
	<tr class=file>
	<td><input type=checkbox name=file[] value="<?php echo fm_enc($f) ?>">
	<td class=itemmenu><span class=item-menu-btn onclick="this.nextElementSibling.firstElementChild.focus()">…</span>
	<div class=inline-actions>
    	<a tabindex=0 href="?p=<?php echo urlencode(FM_PATH) ?>&dl=<?php echo urlencode($f) ?>">Download</a>
    	<a tabindex=0 href="<?php echo fm_enc(FM_ROOT_URL . (FM_PATH != '' ? '/' . FM_PATH : '') . '/' . $f) ?>" target=_blank>Link</a>
    	<a tabindex=0 href="?p=<?php echo urlencode(FM_PATH) ?>&del=<?php echo urlencode($f) ?>" onclick="return confirm('Delete file?');">Delete</a>
    	<a tabindex=0 href="javascript:rename('<?php echo fm_enc(FM_PATH) ?>', '<?php echo fm_enc($f) ?>')">Rename</a>
    	<a tabindex=0 href="?p=<?php echo urlencode(FM_PATH) ?>&copy=<?php echo urlencode(trim(FM_PATH . '/' . $f, '/')) ?>">Copy</a>
	</div>
	<td class=filename><a href="<?php echo $filelink ?>" target=_blank><?php echo $f ?></a><?php echo ($is_link ? ' &rarr; <i>' . readlink($path . '/' . $f) . '</i>' : '') ?>
	<td class=right><span title="<?php printf('%s bytes', $filesize_raw) ?>"><?php echo $filesize ?></span>
	<td><?php echo $modif ?>
	<td><a title="<?php echo 'Change Permissions' ?>" href="?p=<?php echo urlencode(FM_PATH) ?>&chmod=<?php echo urlencode($f) ?>"><?php echo $perms ?></a>
	<td><?php echo $own ?>
	<?php
	flush();
}
?>
</table>
<nav class=bulk-menu hidden>
<!-- <a href="#/select-all" class=btn onclick="checkbox(1);return false;">Select all</a> &nbsp; -->
<!-- <a href="#/unselect-all" class=btn onclick="checkbox(0);return false;">Unselect all</a> &nbsp; -->
<button type=button class=btn onclick="checkbox();">Invert selection</button> &nbsp;
<input type=submit class=btn name=delete id=a-delete value=Delete onclick="return confirm('Delete selected files and folders?')"> &nbsp;
<input type=submit class=btn name=zip id=a-zip value=Zip onclick="return confirm('Create archive?')"> &nbsp;
<input type=submit class=btn name=copy id=a-copy value=Copy>
</nav>
<nav class=item-menu hidden>
<a title=Delete href="?p=<?php echo urlencode(FM_PATH) ?>&del=~" onclick="return confirm('Delete folder?');">Delete</a>
<a title=Rename href="javascript:rename('<?php echo fm_enc(FM_PATH) ?>',~)">Rename</a>
<a title="Copy to..." href="?p=<?php echo urlencode(FM_PATH) ?>&copy=<?php echo urlencode(FM_PATH . '/') ?>~">Copy</a>
<a title="Direct link" href="<?php echo fm_enc(FM_ROOT_URL . (FM_PATH != '' ? '/' . FM_PATH : '') . '/') ?>~" target=_blank>Link</a>
</nav>
<script>
var bm = document.querySelector('.bulk-menu');
document.querySelector('#main-table').addEventListener('click',function(e){
	if(e.target.type=='checkbox' ) {
		var r=e.target.getBoundingClientRect();
		bm.style.left=100+r.left+'px';// e.clientX
		bm.style.top=15+r.top+'px';
		this.querySelector(':checked')?(bm.hidden=0):(bm.hidden=1);
		// e.target.parentElement.parentElement.querySelector('.filename a').href + '&del=1';
	}
	else if ( e.target.className=='item-menu-btn' ) bm.hidden=1;
});
// var itemMenu = document.querySelector('.item-menu');
// function menu(e){
// 	if(e.target.className=='item-menu-btn' ) {
	   // e.target.nextElementSibling.firstElementChild.focus();
	   // console.log(e.target.nextElementSibling.firstElementChild);
// 		var r=e.target.getBoundingClientRect();
//         e.target.className+=' active';
// 		itemMenu.style.left=10+r.left+'px';// e.clientX
// 		itemMenu.style.top=15+r.top+'px';
// 		itemMenu.hidden=0;
// 		console.log(e.target.parentElement.parentElement.querySelector('.filename a').search);
// 		itemMenu.firstElementChild.focus();
// 	} else {
    // if ( !itemMenu.hidden ) {
// 	itemMenu.hidden=1;
// 	this.querySelector('.item-menu-btn.active').classList.remove('active');
// 	}
//  }
// }
// document.querySelector('#main-table').addEventListener('click',menu);
</script>

</form>

<?php
fm_footer();

//--- END

// Functions

/**
 * Delete  file or folder (recursively)
 * @param string $path
 * @return bool
 */
function fm_rdelete($path)
{
	if (is_link($path)) {
		return unlink($path);
	} elseif (is_dir($path)) {
		$objects = scandir($path);
		$ok = true;
		if (is_array($objects)) {
			foreach ($objects as $file) {
				if ($file != '.' && $file != '..') {
					if (!fm_rdelete($path . '/' . $file)) {
						$ok = false;
					}
				}
			}
		}
		return ($ok) ? rmdir($path) : false;
	} elseif (is_file($path)) {
		return unlink($path);
	}
	return false;
}

/**
 * Recursive chmod
 * @param string $path
 * @param int $filemode
 * @param int $dirmode
 * @return bool
 * @todo Will use in mass chmod
 */
function fm_rchmod($path, $filemode, $dirmode)
{
	if (is_dir($path)) {
		if (!chmod($path, $dirmode)) {
			return false;
		}
		$objects = scandir($path);
		if (is_array($objects)) {
			foreach ($objects as $file) {
				if ($file != '.' && $file != '..') {
					if (!fm_rchmod($path . '/' . $file, $filemode, $dirmode)) {
						return false;
					}
				}
			}
		}
		return true;
	} elseif (is_link($path)) {
		return true;
	} elseif (is_file($path)) {
		return chmod($path, $filemode);
	}
	return false;
}

/**
 * Safely rename
 */
function fm_rename($old, $new)
{
	return (!file_exists($new) && file_exists($old)) ? rename($old, $new) : null;
}

/**
 * Copy file or folder (recursively).
 * @param string $path
 * @param string $dest
 * @param bool $upd Update files
 * @param bool $force Create folder with same names instead file
 * @return bool
 */
function fm_rcopy($path, $dest, $upd = true, $force = true)
{
	if (is_dir($path)) {
		if (!fm_mkdir($dest, $force)) {
			return false;
		}
		$objects = scandir($path);
		$ok = true;
		if (is_array($objects)) {
			foreach ($objects as $file) {
				if ($file != '.' && $file != '..') {
					if (!fm_rcopy($path . '/' . $file, $dest . '/' . $file)) {
						$ok = false;
					}
				}
			}
		}
		return $ok;
	} elseif (is_file($path)) {
		return fm_copy($path, $dest, $upd);
	}
	return false;
}

/**
 * Safely create folder
 * @param string $dir
 * @param bool $force
 * @return bool
 */
function fm_mkdir($dir, $force)
{
	if (file_exists($dir)) {
		if (is_dir($dir)) {
			return $dir;
		} elseif (!$force) {
			return false;
		}
		unlink($dir);
	}
	return mkdir($dir, 0777, true);
}

/**
 * Safely copy file
 * @param string $f1
 * @param string $f2
 * @param bool $upd
 * @return bool
 */
function fm_copy($f1, $f2, $upd)
{
	$time1 = filemtime($f1);
	if (file_exists($f2)) {
		$time2 = filemtime($f2);
		if ($time2 >= $time1 && $upd) {
			return false;
		}
	}
	$ok = copy($f1, $f2);
	if ($ok) {
		touch($f2, $time1);
	}
	return $ok;
}

/**
 * Get mime type
 * @param string $file_path
 * @return mixed|string
 */
function fm_get_mime_type($file_path)
{
	if (function_exists('finfo_open')) {
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime = finfo_file($finfo, $file_path);
		finfo_close($finfo);
		return $mime;
	} elseif (function_exists('mime_content_type')) {
		return mime_content_type($file_path);
	} elseif (!stristr(ini_get('disable_functions'), 'shell_exec')) {
		$file = escapeshellarg($file_path);
		$mime = shell_exec('file -bi ' . $file);
		return $mime;
	} else {
		return '--';
	}
}

/**
 * HTTP Redirect
 * @param string $url
 * @param int $code
 */
function fm_redirect($url, $code = 302)
{
	header('Location: ' . $url, true, $code);
	exit;
}

/**
 * Clean path
 * @param string $path
 * @return string
 */
function fm_clean_path($path)
{
	$path = trim($path);
	$path = trim($path, '\\/');
	$path = str_replace(array('../', '..\\'), '', $path);
	if ($path == '..') {
		$path = '';
	}
	return str_replace('\\', '/', $path);
}

/**
 * Get parent path
 * @param string $path
 * @return bool|string
 */
function fm_get_parent_path($path)
{
	$path = fm_clean_path($path);
	if ($path != '') {
		$array = explode('/', $path);
		if (count($array) > 1) {
			$array = array_slice($array, 0, -1);
			return implode('/', $array);
		}
		return '';
	}
	return false;
}

/**
 * Get nice filesize
 */
function fm_get_filesize($bytes)
{
	if ( $bytes < 1024 ) return $bytes . " B";
	$factor = floor((strlen($bytes) - 1) / 3);
	return sprintf("%.1f", $bytes / pow(1024, $factor)) .' '. substr('BKMGTP', $factor, 1 );
}

/**
 * Get info about zip archive
 * @param string $path
 * @return array|bool
 */
function fm_get_zif_info($path)
{
	if (function_exists('zip_open')) {
		$arch = zip_open($path);
		if ($arch) {
			$filenames = [];
			while ($zip_entry = zip_read($arch)) {
				$zip_name = zip_entry_name($zip_entry);
				$zip_folder = substr($zip_name, -1) == '/';
				$filenames[] = array(
					'name' => $zip_name,
					'filesize' => zip_entry_filesize($zip_entry),
					'compressed_size' => zip_entry_compressedsize($zip_entry),
					'folder' => $zip_folder
				);
			}
			zip_close($arch);
			return $filenames;
		}
	}
	return false;
}

/**
 * Encode html entities
 */
function fm_enc($text)
{
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * This function scans the files folder recursively, and builds a large array
 */
function scan($dir){
	$files = [];
	$_dir = $dir;
	$dir = FM_ROOT_PATH.'/'.$dir;
	if(file_exists($dir)){
		foreach(scandir($dir) as $f) {// foreach ( array_diff( scandir($dir), ['.','..'] ) as $f) {
			if(!$f || $f[0] == '.') {
				continue; // Ignore hidden files
			}

			if(is_dir($dir . '/' . $f)) {
				// The path is a folder
				$files[] = array(
					"name" => $f,
					"type" => "folder",
					"path" => $_dir.'/'.$f,
					"items" => scan($dir . '/' . $f), // Recursively get the contents of the folder
				);
			} else {
				// It is a file
				$files[] = array(
					"name" => $f,
					"type" => "file",
					"path" => $_dir,
					"size" => filesize($dir . '/' . $f) // Gets the size of this file
				);
			}
		}
	}
	return $files;
}

/**
 * Save message in session
 * @param string $msg
 * @param string $status
 */
function fm_set_msg($msg, $status = 'ok')
{
	$_SESSION['message'] = $msg;
	$_SESSION['status'] = $status;
}

/**
 * Check if string is in UTF-8
 * @param string $string
 * @return int
 */
function fm_is_utf8($string)
{
	return preg_match('//u', $string);
}

function fm_get_file_type($file_path)
{
	$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

	if ($ext == 'zip') {
		$type = 'archive';
	} elseif (in_array($ext, ['ico', 'gif', 'jpg', 'jpeg', 'jpc', 'jp2', 'jpx', 'xbm', 'wbmp', 'png', 'bmp', 'tif', 'tiff', 'psd', 'svg', 'webp', 'avif'])) {
		$type = 'image';
	} elseif (in_array($ext, ['wav', 'mp3', 'ogg', 'm4a'])) {
		$type = 'audio';
	} elseif (in_array($ext, ['webm', 'mp4', 'm4v', 'ogm', 'ogv', 'mov'])) {
		$type = 'video';
	} elseif (in_array($ext, ['txt', 'css', 'ini', 'conf', 'log', 'htaccess', 'passwd', 'ftpquota', 'sql', 'js', 'json', 'sh', 'config',
	'php', 'php4', 'php5', 'phps', 'phtml', 'htm', 'html', 'shtml', 'xhtml', 'xml', 'xsl', 'm3u', 'm3u8', 'pls', 'cue', 'eml', 'msg',
	'csv', 'tev', 'bat', 'twig', 'tpl', 'md', 'gitignore', 'less', 'sass', 'scss', 'c', 'cpp', 'cs', 'py', 'map', 'lock', 'dtd'])) {
		$type = "text";
	} else {
		$mime_type = fm_get_mime_type($file_path);
		if ( substr($mime_type, 0, 4) == 'text' || in_array($mime_type, ['application/xml','application/javascript','application/x-javascript','image/svg+xml','message/rfc822'])) {
			$type = "text";
		}
	}
	return $type;
}


/**
 * Class to work with zip files (using ZipArchive)
 */
class FM_Zipper
{
	private $zip;

	public function __construct()
	{
		$this->zip = new ZipArchive();
	}

	/**
	 * Create archive with name $filename and files $files (RELATIVE PATHS!)
	 * @param string $filename
	 * @param array|string $files
	 * @return bool
	 */
	public function create($filename, $files)
	{
		$res = $this->zip->open($filename, ZipArchive::CREATE);
		if ($res !== true) {
			return false;
		}
		foreach ( (array) $files as $f) {
			if (!$this->addFileOrDir($f)) {
				$this->zip->close();
				return false;
			}
		}
		$this->zip->close();
		return true;
	}

	/**
	 * Extract archive $filename to folder $path (RELATIVE OR ABSOLUTE PATHS)
	 * @param string $filename
	 * @param string $path
	 * @return bool
	 */
	public function unzip($filename, $path)
	{
		$res = $this->zip->open($filename);
		if ($res !== true) {
			return false;
		}
		if ($this->zip->extractTo($path)) {
			$this->zip->close();
			return true;
		}
		return false;
	}

	/**
	 * Add file/folder to archive
	 * @param string $filename
	 * @return bool
	 */
	private function addFileOrDir($filename)
	{
		if (is_file($filename)) {
			return $this->zip->addFile($filename);
		} elseif (is_dir($filename)) {
			return $this->addDir($filename);
		}
		return false;
	}

	/**
	 * Add folder recursively
	 * @param string $path
	 * @return bool
	 */
	private function addDir($path)
	{
		if (!$this->zip->addEmptyDir($path)) {
			return false;
		}
		$objects = scandir($path);
		if (is_array($objects)) {
			foreach ($objects as $file) {
				if ($file != '.' && $file != '..') {
					if (is_dir($path . '/' . $file)) {
						if (!$this->addDir($path . '/' . $file)) {
							return false;
						}
					} elseif (is_file($path . '/' . $file)) {
						if (!$this->zip->addFile($path . '/' . $file)) {
							return false;
						}
					}
				}
			}
			return true;
		}
		return false;
	}
}

//--- templates functions

/**
 * Show current path
 * @param string $path
 */
function fm_path()
{
	$path = fm_clean_path(FM_PATH);
	$breadcrumb = "<a href='?p=' title='" . FM_ROOT_PATH . "'>home</a>";
	if ($path) {
		$links = [];
		$parent = '';
		foreach( explode('/', $path) as $part ) {
			$parent = trim($parent . '/' . $part, '/');
			$links[] = "<a href='?p=". urlencode($parent) ."'>" . fm_enc($part) . "</a>";
		}
		$breadcrumb .= ' / ' . implode(' / ', $links) . ' /&nbsp;';
	}
	echo "<div id=breadcrumb>{$breadcrumb}</div>";
}


/**
 * Show menu block
 */
function fm_header_menu( $page='', $menu_items='' )
{
	echo "<nav class='main-nav right'>";
	if ( $page == "main" ) {
		echo "<span id=logo>filmgr</span>";
		echo "<a href='?p=&pad=php' target=_blank>scratch pad</a> "
			."<a href='?p=" . urlencode(FM_PATH) . "&upload'>upload</a> "
			."<a onclick=\"newf('". fm_enc(FM_PATH) ."','directory')\">new dir</a> "
			."<a onclick=\"newf('". fm_enc(FM_PATH) ."','file')\">new file</a> ";
		if (FM_USE_AUTH) echo "<a title=Logout href='?logout=1'>logout</a>";
	} else {
		echo "<span style='float:left'>";
		fm_path();
		if ( !empty($_GET['f'])) echo " <strong>{$_GET['f']}</strong>";
		echo "</span>";
		echo $menu_items;
	}
	echo "</nav>";
}


/**
 * Show message from session
 */
function fm_message()
{
	if (isset($_SESSION['message'])) {
		$class = isset($_SESSION['status']) ? $_SESSION['status'] : 'ok';
		echo '<p class="message ' . $class . '">' . $_SESSION['message'] . '</p>';
		unset($_SESSION['message']);
		unset($_SESSION['status']);
	}
}

/**
 * Show page footer
 * TODO what's the point of the # in newf
 */
function fm_footer()
{
	?>
<script>
function rename(p,f) {
	var n = prompt('new name?', f);
	n && n != f && (window.location.search = "p="+ encodeURIComponent(p) +"&ren="+ encodeURIComponent(f) +"&to="+ encodeURIComponent(n))
}

function newf(p,t) {
	var n = prompt( t +' name?');
	n && (window.location.hash = "#", window.location.search = "p=" + encodeURIComponent(p) + "&new=" + encodeURIComponent(n) + "&type=" + t )
}

function checkbox(b) {
	document.getElementsByName("file[]").forEach(function(t){ b===undefined ? t.checked=!t.checked : (t.checked=b); });
}

function backup(p,f) {
	var n = new XMLHttpRequest,
		a = "path=" + p + "&file=" + f + "&type=backup&ajax=true";
	return n.open("POST", "", !0), n.setRequestHeader("Content-type", "application/x-www-form-urlencoded"), n.onreadystatechange = function() {
		4 == n.readyState && 200 == n.status && alert(n.responseText)
	}, n.send(a), !1
}

document.addEventListener('keydown',function(e){
	if( e.key == 's' && ( e.metaKey || e.ctrlKey )) { e.preventDefault(); save();}
});

function save() {
	var n = editor.getValue(),
	x = new XMLHttpRequest,
	form = new FormData();
	if (typeof(n)==="string") {
		x.open('POST','');
		x.onload=function(){
			x.r = x.response.split('~');
			var saveBtn = document.getElementById('save');
			saveBtn.textContent = x.r[1];
			saveBtn.className = x.r[0];
			setTimeout( function(){saveBtn.textContent="Save";saveBtn.className='';},5000);
		};
		form.append('ajax','save');
		form.append('data',n);
		x.send(form);
	}
}
</script>
<?php
}

function fm_load_ace() {
	
	if ( !empty($_GET['pad']) ) {
		$file = 'pad.' . $_GET['pad'];// pad should be set to a file extension
	} elseif ( !empty($_GET['f']) && FM_EDIT_FILE ) {
		$file = $_GET['f'];
	} else {
		return;
	}
	$ace_version = "1.4.13";
	
	echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/ace/{$ace_version}/ace.min.js'></script>";
	
	$dot = strrpos( $file , '.' );// if only . is at the beginning (.htaccess) $dot will be 0 and fail the conditional in next lie, which is ideal.
	$ext = $dot ? substr( $file , 1+$dot ) : 'txt';
	
	$plain_exts = [ 'txt', 'log' ];// extensions treated as plain text (txt was set for files with no extension, above)
	$typical_exts = [ 'php' => 'php', 'js' => 'javascript', 'css' => 'css', 'html' => 'html' ];// why not hardcode the usual suspects for WP
	$setmode = "";

	if ( isset( $typical_exts[ $ext ] ) )// see if file is one of the normal suspects
	{
		$setmode = "editor.session.setMode('ace/mode/{$typical_exts[ $ext ]}');";
	}
	elseif ( ! in_array( $ext, $plain_exts ) )// if not plain, auto detect using ACE modelist
	{
		echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/ace/{$ace_version}/ext-modelist.min.js'></script>";
		$setmode = "
		var modelist = ace.require('ace/ext/modelist');
		var mode = modelist.getModeForPath('{$file }').mode;
		editor.session.setMode(mode);";
	}

	echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/ace/{$ace_version}/ext-language_tools.min.js'></script>";
	echo "<script>ace.config.set('basePath', 'https://cdnjs.cloudflare.com/ajax/libs/ace/{$ace_version}/');
	var editor = ace.edit('editor');
	editor.setShowPrintMargin(false);
	editor.focus();
	$setmode";
	echo "
	ace.require('ace/ext/language_tools');
	editor.setOptions({
        enableBasicAutocompletion: true,
        enableSnippets: true,
        enableLiveAutocompletion: true
    });";
	echo "</script>";
}


/**
 * Show page header
 */
function fm_header($title="filmgr")
{
	header("Content-Type: text/html; charset=utf-8");
	header("Cache-Control: no-store");
	?>
<!DOCTYPE html>
<meta charset=utf-8>
<meta name=viewport content="width=device-width, initial-scale=1">
<meta name=robots content=noindex>
<title><?php echo $title ?></title>
<style>
* {
	margin: 0;
}
button,
input,
select {
	color: inherit;
	font: inherit;
}
a {
	color: inherit;
	text-decoration: none;
	cursor: pointer;
}
.filename {
	word-break: break-word;
}
p {
	margin-bottom: 1em;
}
body {
	font: 14px sans-serif;
	background: #faf8f5;
	margin-top: 44px;
	padding: 12px 24px;
}
a:hover,
[type=submit]:hover {
	color: #f66;
}
td {
	padding: 7px;
}
#main-table {
	width: 100%;
	border-collapse: collapse;
}
tr:nth-of-type(even) {
	background: #fff;
}
tr:not(.th):hover {
	background: #eef;
}
.right {
	text-align: right;
}
.center,
.login-form {
	text-align: center;
}
#fm_usr, #fm_pwd {
	/* border-bottom: 1px solid; */
	margin: 1em;
	/* padding: 1em 1em 0; */
	/* text-align: center; */
}
.error {
	color: red;
}
.alert {
	color: orange;
}
#save.ok {
    background: #8b5;
    color: #fff;
}
#save.fail {
    background: red;
    color: #fff;
}
.preview-img {
	max-width: 100%;
	background: url('data:image/png;base64, iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAKklEQVR42mL5//8/Azbw+PFjrOJMDCSCUQ3EABZc4S0rKzsaSvTTABBgAMyfCMsY4B9iAAAAAElFTkSuQmCC');
}
.preview-video {
	position: relative;
	max-width: 100%;
	height: 0;
	padding-bottom: 62.5%;
	margin-bottom: 10px;
}
.preview-video video {
	position: absolute;
	width: 100%;
	height: 100%;
	left: 0;
	top: 0;
	background: #000;
}
#editor {
    position: absolute;
    top: 43px;
    bottom: 0;
    left: 0;
    right: 0;
	font-size: 14px;
}
.btn,
[type=submit] {
	background: #fff;
	padding: .5ex 1ex;
	border: 1px solid;
	cursor: pointer;
}
.itemmenu {
    position: relative;
}
.inline-actions {
	box-shadow: 0 0 5px #aaa;
    background: #fff;
    padding: 12px;
    position: absolute;
    top: 70%;
    left: -999px;
}
.inline-actions:focus-within {
    left: 70%;
}
.inline-actions a {
    padding: 7px;
    display: block;
}
.main-nav,
/*.item-menu,*/
.bulk-menu {
	box-shadow: 0 0 5px #aaa;
	background: #fff;
	padding: 12px;
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	z-index: 9;
}
.main-nav a {
    padding: 0 8px;
}
#breadcrumb {
    padding: 12px 6px;
}
.main-nav #breadcrumb {
    padding: 0;
    display: inline;
}
.item-menu,
.bulk-menu {
	top: 20%;
	left: 20%;
	/* bottom: 2em; */
	right: auto;
}
#editor:not(.ace_editor),
textarea[name=savedata] {
	display: none;
}
#logo {
	float: left;
	letter-spacing: 3px;
    font-variant-caps: small-caps;
}
[type=checkbox] {
    appearance: none;
    width: 2.3ex;
    height: 2.3ex;
    border: 2px solid #bbb;
    position: relative;
    border-radius: 3px;
}
[type=checkbox]:checked {
    background: #f88;
}
.item-menu-btn {
    transform: rotate(90deg);
    display: inline-block;
    cursor: pointer;
    font-weight: 700;
    color: #777;
}

@media (max-width:800px) {
	#main-table td:not(:nth-child(1)):not(:nth-child(2)):not(:nth-child(3)),
	#logo,
	.main-nav #breadcrumb,
	.ace_gutter {
		display: none;
	}
	.ace_scroller {
		left: 0 !important;
	}
	#editor {
		font-size: 10px;
	}
	body {
		padding: 0;
	}
}
</style>
<?php
}