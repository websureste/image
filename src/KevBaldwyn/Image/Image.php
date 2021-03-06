<?php namespace KevBaldwyn\Image;

use Config;
use Input;

class Image {

	private $worker;
	private $cache;
	private $cacheLifetime; // minutes

	private $pathStringbase = '';
	private $pathString;


	public function __construct($worker, \Illuminate\Cache\CacheManager $cache, $cacheLifetime, $pathString) {
		$this->worker = $worker;
		$this->cache = $cache;
		$this->cacheLifetime = $cacheLifetime;
		$this->pathStringBase = $pathString . '?';
	}


	public function getWorker() {
		return $this->worker;
	}


	public function responsive(/* any number of params */) {
		$params = func_get_args();
		if(count($params) <= 1) {
			throw new \Exception('Not enough params provided to generate a responsive image');
		}
		
		list($rule, $transform) = $this->getPathOptions($params);

		// write out the reposinsive url part
		$this->pathString .= ';' . $rule . ':' . $transform . '&' . Config::get('image::vars.responsive_flag') . '=true';
		return $this;
	}


	public function path(/* any number of params */) {
		
		$params = func_get_args();
		if(count($params) <= 1) {
			throw new \Exception('Not enough params provided to generate an image');
		}
		
		list($img, $transform) = $this->getPathOptions($params);

		// write out the resize path
		$this->pathString = $this->pathStringBase;
		$this->pathString .= Config::get('image::vars.image') . '=' . $img;
		$this->pathString .= '&' . Config::get('image::vars.transform') . '=' . $transform;
		return $this;
	}


	public function serve() {
		if(Input::get(Config::get('image::vars.responsive_flag')) == 'true') {
			$operations = $this->worker->getResponsiveOperations($_COOKIE['Imagecow_detection'], Input::get(Config::get('image::vars.transform')));
		}else{
			$operations = Input::get(Config::get('image::vars.transform'));
		}

		// is there ant merit in this being base_path()?
		// if it was base_path() then any image on the filesystem could be served - is this actually desirable?
		$imgPath = public_path() . Input::get(Config::get('image::vars.image'));
		
		$checksum  = md5($imgPath . ';' . serialize($operations));
		$cacheData = $this->cache->get($checksum);
		
		if($cacheData) {

			// using cache
			if (($string = $cacheData['data']) && ($mimetype = $cacheData['mime'])) {
				header('Content-Type: '.$mimetype);
				die($string);
			}else{
				throw new \Exception('There was an error with the image cache');
			}

		}else{

			$this->worker->load($imgPath)
						 ->transform($operations);
			
			$cacheData = array('mime' => $this->worker->getMimeType(),
							   'data' => $this->worker->getString());

			$this->cache->put($checksum, $cacheData, $this->cacheLifetime);

			$this->worker->show();
			
			// if the script didn't die then it will have an error (Imagecow::show() dies when it returns image data)
			throw new \Exception($this->worker->getError()->getMessage());
			
		}	
		
	}


	public function js($publicDir = '/public') {
		
		$jsFile = Config::get('image::js_path');

		// hacky hack hack
		// if .js file doesn't exist in defined location then copy it there?! (or throw an error?)
		if(!file_exists(base_path() . $jsFile)) {
			throw new \Exception('Javascript file does not exists! Please copy /vendor/imagecow/imagecow/Imagecow/Imagecow.js to ' . $jsFile);
		}

		// check if the path starts with "public"
		// if so then we need to remove it 
		// - the file_exists is checking the server path not the web path
		// will this always be the case?
		//$path = (preg_match('/^\/?public\//', $jsFile)) ? str_replace('public/', '', $jsFile) : $jsFile;
		
		// nicer to pass it through as a param instead:
		$path = (!is_null($publicDir)) ? str_replace($publicDir, '', $jsFile) : $jsFile;

		$str  = '
		<script src="' . $path . '" type="text/javascript" charset="utf-8"></script>
		<script type="text/javascript">
    		Imagecow.init();
		</script>';

		return $str;

	}


	public function __toString() {
		return $this->pathString;
	}


	private function getPathOptions($params) {

		$first = $params[0];

		foreach($params as $key => $param) {
			if($key > 0) {
				$transformA[] = $param;
			}
		}
		$transform = implode(',', $transformA);

		return array($first, $transform);

	}

}