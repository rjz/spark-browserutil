<?php
/**
 *	Browser-based utility for managing Codeigniter Sparks (http://getsparks.org)
 *
 *	Two parts, here:
 *	(1) PacMan, a mini implementation of the spark package manager
 *	(2) SparkHelper, the utility itself
 *
 *	@author	RJ Zaworski	<rj@rjzaworski.com>
 */
define('APPPATH', dirname(__FILE__));
define('SPARK_PATH', './sparks');

$l10n_table = array(

	'index'          => 'Installed Sparks',
	'install_spark'  => '<strong>%s</strong> Installed successfully!',
	'install_already'=> 'Already installed, boss',
	
	'menu_my'        => 'My Sparks',
	'menu_search'    => 'Get Sparks',
	
	'search_results' => 'Search results for &ldquo;%s&rdquo;',
	'single'         => 'Details for %s',

	'search'         => 'Search sparks:',
	'search_hint'    => 'Enter a keyword or term, e.g. <strong>RSS</strong>',
	'search_result'  => 'Results for %s',
	
	'title_install'  => 'Install',
	'prompt_install' => '<h5>I\'ve looked <em>everywhere</em>...</h5><p>The sparks system doesn\'t seem to be installed, yet. Would you like to install it now?</p><p><a class="btn primary" href="?action=install_utility">Install</a></p>'
);

/**
 *
 */
class PacMan {

	protected $sources;

	/**
	 *	We can build it! Fire up manager and scan for available sources
	 */
	public function __construct () {
	
		$this->sources = array();

		try {
			if ($fp = fopen(APPPATH . '/tools/lib/spark/sources', 'r')) {

				while ($line = fgets($fp)) {

					$line = trim($line);
					if ($line && !preg_match('/^\s*#/', $line)) {
						$this->sources[] = $line;
					}
				}

				fclose($fp);
			}
			
			if (count($this->sources) == 0) {
				throw( new Exception('no sources') );
			}

		} catch (Exception $e) {
			$this->sources[] = 'sparks.oconf.org';
		}
	}

	/**
	 *	Retrieve details on an individual spark
	 *
	 *	@param	string	the name of the spark 
	 *	@param	string	the ref/tag/version of the spark to retrieve
	 *	@return	object	the skivvy on the spark in question
	 */
	public function spark_detail ($name, $version = 'HEAD') {

		foreach ($this->sources as $source) {

			// [FIXME]
			$result = @file_get_contents("http://$source/api/packages/$name/versions/$version/spec");
			//$result = file_get_contents('http://localhost/ci/~tools/atomizer.json');
			
			$result = json_decode($result);

			if ($result && $result->success) {
				return $result->spec;
			}
		}
	}

	/**
	 *	Check to see if a spark can be installed
	 *
	 *	@param	object	a spark object (loaded from spec file)
	 *	@return	boolean
	 */
	public function can_install ($spark) {

		if ($spark->is_deactivated) {
			// deactivated
			return false;
		}
		
		if ($spark->is_unsupported) {
			// unsupported
			return false;
		}
		
		$install_path = SPARK_PATH . "/$spark->name/$spark->version";

		if (is_dir($install_path)) {
			// already installed
			return false;
		}

		return true;
	}
	
	/**
	 *	Install a spark
	 *
	 *	@param	string	the name of the spark to install
	 *	@param	string	(optional) the version of the spark to install (default: HEAD)
	 *	@return	mixed	boolean true or an error string
	 */
	public function install ($name, $version = 'HEAD') {

		$repos = array(
			'git' => "git clone %1\$s %2\$s\n cd %2\$s; git checkout %3\$s -b %4\$s",
			'hg'  => 'hg clone -r%3\$s %1\$s %2\$s',
			'zip' => ''
		);

		$spark = $this->spark_detail($name, $version);

		if ($this->can_install($spark)) {
			
			$temp_token = 'spark-' . $name . '-' . time();
			$temp_path = sys_get_temp_dir() . '/' . $temp_token;
			$install_path = SPARK_PATH . "/$spark->name/$spark->version";

			try {

				@mkdir(SPARK_PATH);
				@mkdir(SPARK_PATH . "/$spark->name");

				$command = sprintf($repos[$spark->repository_type], $spark->base_location, $temp_path, $spark->tag, $temp_token);
				$result = exec($command);

				if (!file_exists($temp_path)) {
					throw new Exception('Failed grabbing spark');
				}

				$this->rrm_dir("$temp_path/.git");
				$this->rrm_dir("$temp_path/.hg");
				$this->rmv_dir($temp_path, $install_path);
				$this->rrm_dir($temp_path);
				
				return true;

			} catch (Exception $e) {}
		}

		return 'error installing spark.';
	}
	
	/**
	 *	Uninstall a spark
	 *
	 *	@param	string	the name of the spark to install
	 *	@param	string	(optional) the version of the spark to install (default: HEAD)
	 */
	public function remove ($name, $version = 'HEAD') {

		$spark_dir = SPARK_PATH . "/$name";
		$install_path = $spark_dir . "/$version";
		
		if (is_dir($install_path)) {
			$this->rrm_dir($install_path);
			
			$files = scandir($spark_dir);

			// check for any other versions
			foreach ($files as $file) {
				if ($file != '.' && $file != '..') {
					return;
				}
			}
			
			// remove the spark directory
			$this->rrm_dir($spark_dir);
		}
	}

	/**
	 *	Straight outta lib/spark/spark_type.php
	 */
    private function rmv_dir($src, $dst) { 
		$dir = opendir($src); 
		@mkdir($dst); 
		while(false !== ( $file = readdir($dir)) ) { 
			if (( $file != '.' ) && ( $file != '..' )) { 	
				if ( is_dir($src . '/' . $file) ) { 
					$this->rmv_dir($src . '/' . $file,$dst . '/' . $file); 
				}
				else { 
					rename($src . '/' . $file,$dst . '/' . $file); 
				} 
			} 
		} 
		closedir($dir); 
	}

	/**
	 *	Straight outta lib/spark/spark_type.php +chmod to 
	 *	handle locked files
	 */
    private function rrm_dir($dir) { 
		if (is_dir($dir)) { 
			$files = scandir($dir); 
			foreach ($files as $file) { 
				if ($file != '.' && $file != '..') { 
					if (is_dir($dir . '/' . $file)) {
						$this->rrm_dir($dir . '/' . $file); 
					}
					else {
						$file = $dir . '/' . $file;
						chmod($file, 0777);
						unlink($file);
					}
				} 
			} 
			reset($files); 
			rmdir($dir);
		} 
	}
	
	/**
	 *	Search for sparks across all repositories
	 *
	 *	@param	String	the terms to search for
	 *	@return	Array	an array of matched sparks
	 */
	public function search ($terms) {

		$search_results = array();
	
		foreach ($this->sources as $source) {
			
			$result = @file_get_contents("http://$source/api/packages/search?q=" . urlencode($terms));
			$result = json_decode($result);
		
			if ($result && $result->success) {
				$search_results = array_merge ($search_results, $result->results);
			}
		}
		
		return $search_results;
	}
}

/**
 *	Spark Helper Utility
 *
 */
class SparkHelper {

	/**
	 *	Template/view data
	 *	@type	array
	 */
	protected $data;

	/**
	 *	Set default view data 
	 */
	public function __construct() {

		global $l10n_table;
	
		// whitelist for data
		$data_defaults = array(
			'title'        => $l10n_table['index'],
			'message'      => '',
			'message_type' => '',
			'sparks'       => array()
		);
		
		$this->data = $data_defaults;
	}

	/**
	 *	getter: access protected view data
	 */
	public function __get ($key) {

		if (array_key_exists($key, $this->data)) {
			return $this->data[$key];
		}
		
		return null;
	}

	/**
	 *	setter: update protected view data
	 */
	public function __set ($key, $value) {

		if (array_key_exists($key, $this->data)) {
			$this->data[$key] = $value;
		}
	}

	/**
	 *	Check to see if sparks are installed
	 *	@return boolean
	 */
	private function _check_sparks() {
		return file_exists('tools/spark');
	}

	/**
	 *	Check to see what sparks are installed
	 *	@return	array
	 */
	public function scan_sparks () {

		if (!is_dir(SPARK_PATH)) {
			return;
		}

		$sparks = array();

		foreach (scandir(SPARK_PATH) as $item) {

			if (is_dir(SPARK_PATH . "/$item") && $item[0] != '.') {

				foreach (scandir(SPARK_PATH . "/$item") as $ver) {
					if (is_dir(SPARK_PATH . "/$item/$ver") && $ver[0] != '.') {
						$sparks[] = (object)array(
							'name' => $item,
							'version' => $ver
						);
					}
				}
			
			}
		}

		return $sparks;
	}

	/**
	 *	Check to see what versions of a spark are installed
	 *
	 *	@param	string	the name of the spark
	 *	@return	array	an array of versions
	 */
	public function installed_versions ($name) {

		$spark_dir = SPARK_PATH . "/$name";
		$versions = array();

		if (is_dir($spark_dir)) { 
			$entries = scandir($spark_dir);

			foreach ($entries as $entry) {
				if ($entry[0] != '.' && filetype($spark_dir . '/' . $entry) == 'dir') {
					$versions[] = $entry;
				}
			}
		}
		
		return $versions;
	}

	/**
	 *	Install the sparks utility
	 */
	public function install_utility() {

		$script = file_get_contents('http://getsparks.org/go-sparks');

		if (!$script) {
			die ('failed retrieving sparks installer');
		} 

		// ok, we've got the install script. Time to use some really
		// `eval` code to run it...

		ob_start();
			eval ($script);
			$response = ob_get_contents();
		ob_end_clean();
		
		if (strpos($response, 'installed successfully!')) {
			$result = 'Sparks installed!';
			$this->message_type = 'success';
		} else {
			$result = 'There was a problem :-(';
			$this->message_type = 'error';
		}
		
		$this->message = '<h5>' . $result . '</h5><pre>' . $response . '</pre>';
	}

	/**
	 *	Handle program flow
	 */
	public function controller() {

		global $l10n_table;
	
		if (!isset($_GET['action'])) {
			$_GET['action'] = 'index';
		}

		$action = $_GET['action'];

		// prompt user to install sparks if they haven't already
		if ($action != 'install_utility' && $this->_check_sparks() == false) {
			$action = 'prompt_install_utility';
		}

		switch ($action) {
		
		case 'prompt_install_utility':
			$this->title = $l10n_table['title_install'];
			$this->message = $l10n_table['prompt_install'];
		break;
		
		case 'index':
			$this->sparks = $this->scan_sparks();
		break;

		case 'install_utility':
			if ($this->_check_sparks()) {
				$this->message_type = 'error';
				$this->title = $l10n_table['title_install'];
				$this->message = $l10n_table['install_already'];
			} else {
				$this->install_utility();
			}
		break;
		
		case 'install':

			$sources = new PacMan();
			$name = $_GET['name'];
			$msg = $sources->install($name);

			if ($msg === true) {
				$this->message_type = 'success';
				$this->message = sprintf($l10n_table['install_spark'], $name);
			} else {
				$this->message_type = 'error';
				$this->message = $msg;
			}

			$this->sparks = $this->scan_sparks();

		break;
		
		case 'uninstall':

			$sources = new PacMan();
			$name = $_GET['name'];
			$version = $_GET['version'];
			$sources->remove($name, $version);

			$this->sparks = $this->scan_sparks();

		break;
		
		case 'search':
			$sources = new PacMan();
			$terms = $_GET['s'];
			$this->sparks = $sources->search($terms);
			$this->title = sprintf($l10n_table['search_results'], $terms);
		break;
		}
		
		foreach ($this->data['sparks'] as &$spark) {

			$versions = $this->installed_versions($spark->name);
			if (count($versions)) {
				$spark->installed = true;
				$spark->versions = $versions;
			} else {
				$spark->installed = false;
			}
		}
	}
}

$util = new SparkHelper();
$util->controller();

?>
<!doctype html>
<html>
<head>
<title><?php echo $util->title; ?></title>
<link rel="stylesheet" href="http://twitter.github.com/bootstrap/1.4.0/bootstrap.min.css">

</head>
<body>

<div class="container">

<div class="page-header">
<h1><?php echo $util->title; ?></h1>
</div>

<?php if ($util->message): ?>
<div class="alert-message block-message <?php echo $util->message_type; ?>">
	<?php echo $util->message; ?>
</div>
<?php endif; //end message ?>

<?php if (count($util->sparks)): ?>
<?php foreach($util->sparks as $spark): ?>
<ul>
	<li>
<?php if ($spark->installed): ?>
		<h5 class="name"><?php echo $spark->name; ?> <small>v<?php echo implode($spark->versions, ', '); ?></small></h5>
<?php foreach ($spark->versions as $version): ?>
		<a href="?action=uninstall&name=<?php echo $spark->name; ?>&version=<?php echo $version; ?>">Remove v<?php echo $version; ?></a> 
<?php endforeach; ?>
<?php else: // sparks that haven't been installed yet ?>
		<h5 class="name"><?php echo $spark->name; ?></h5>
		<div class="row">
		<div class="span4">
		<dl>
			<dt>Author:</dt>
			<dd><?php echo $spark->username; ?></dd>
			<dt>Description:</dt>
			<dd><?php echo $spark->summary; ?></dd>
			<dt>Repo type:</dt>
			<dd><?php echo $spark->repository_type; ?></dd>
		</dl>
		</div><!--.span8-->
		<div class="span11">
			<p><?php echo preg_replace( '#(https?://.*?)(?:\s|$)#', ' <a href="$1">$1</a> ', $spark->description ); ?></p>
			<a class="btn primary" href="?action=install&name=<?php echo $spark->name; ?>">Install <strong><?php echo $spark->name; ?></strong></a>
		</div><!--.span8-->
		</div><!--.row-->
<?php endif; ?>
		<hr />
	</li>
</ul>
<?php endforeach; ?>
<?php endif; // listing sparks ?>

<form class="form-stacked well">
	<input type="hidden" name="action" value="search" />
	<div class="input">
		<label for="s"><?php echo $l10n_table['search']; ?></label>
		<input type="text" name="s" id="s" />
		<input class="btn" type="submit" value="Search" />
		<span class="help-block"><?php echo $l10n_table['search_hint']; ?></span>
	</div>
</form>

</div><!--.container-->

</body>
</html>