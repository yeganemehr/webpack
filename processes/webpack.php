<?php
namespace packages\webpack\processes;
use \packages\base\log;
use \packages\base\IO\file\local as file;
use \packages\base\IO\directory\local as directory;
use \packages\base\packages;
use \packages\base\json;
use \packages\base\process;
use \packages\base\loader;
use \packages\base\frontend\theme;
use \packages\base\frontend\source;
use \packages\npm;
class webpack extends process{
	private $repositoryDirectory;
	private $repository;
	private $inotifyHandler;
	private $watchFiles = [];
	private $npms = [];
	private function getListOfSources():array{
		$log = log::getInstance();
		$sources = theme::get();
		if(!$sources){
			$log->reply("empty");
			$log->debug("try to loading themes");
			loader::themes();
			$log->debug("get list of frontend sources");
			$sources = theme::get();
		}
		return $sources;
	}
	public function run(){
		log::setLevel('debug');
		log::setIndentation(' ', 2);
		$log = log::getInstance();
		$log->debug("get list of frontend sources");
		$sources = $this->getListOfSources();
		if(!$sources){
			$log->reply("empty");
			$log->debug("nothing to do");
			return true;
		}
		foreach($sources as $source){
			$log->debug("prepare npm packages for ".$source->getPath());
			$this->prepareSource($source);
			$log->reply("Success");
		}
		$log->debug("check for webpack installtion");
		if($this->isWebpackInstalled()){
			$log->reply("already installed");
		}else{
			$log->reply("notfound");
			$log->info("install webpack");
			$this->installWebpack();
			$log->reply('Success');
		}
		$log->info("Config Webpack");
		$this->config($sources);
		$log->reply("Success");
		$log->info("Run Webpack");
		$this->runInCommandLine($sources);
		$log->reply("Success");
		
	}
	private function prepareSource(source $source){
		$log = log::getInstance();
		$repository = new npm\Repository(new directory($source->getPath()));
		foreach($source->getAssets('package') as $asset){
			$string = $asset['name'].(isset($asset['version']) ? '@'.$asset['version'] : '');
			$log->debug("istall {$string}");
			$repository->install($string);
			$log->reply("Success");
		}
	}
	private function getRepository():npm\Repository{
		if(!$this->repositoryDirectory){
			$log = log::getInstance();
			$this->repositoryDirectory = new directory(packages::package('webpack')->getFilePath('storage/private/frontend'));
			$log->debug("looking for repository directory (", $this->repositoryDirectory->getPath(), ')');
			if($this->repositoryDirectory->exists()){
				$log->reply("found");
			}else{
				$log->reply("Notfound");
				$log->debug("create it");
				$this->repositoryDirectory->make(true);
				$log->debug("Success");
			}

			$this->repository = new npm\Repository($this->repositoryDirectory);
			$config = $this->repositoryDirectory->file("package.json");

			$log->debug("looking for package.json in repository");
			if(!$config->exists()){
				$log->reply("notfound");
				$log->debug("creating it");
				if($config->write(json\encode(array(
					'name' => 'webuilder-webpack',
					'private' => false,
					'dependencies' => $this->webpackPackages()
				), json\PRETTY | json\FORCE_OBJECT))){
					$log->reply("Success");
				}else{
					$log->reply()->fatal('Failed');
				}
			}else{
				$log->reply("Found");
			}
		}
		return $this->repository;
	}
	private function isWebpackInstalled():bool{
		return (bool)$this->getRepository()->isInstalled('webpack');
	}
	private function installWebpack(){
		$log = log::getInstance();
		foreach($this->webpackPackages() as $package => $version){
			$log->info("install {$package}@{$version}");
			$this->getRepository()->install($package.'@'.$version);
			$log->reply("Success");
		}
	}
	private function webpackPackages():array{
		return [
			'webpack' => '~2.6.0',
			'typescript' => '~2.3.4',
			'less' => '~2.7.2',
			'less-plugin-clean-css' => '~1.5.1',
			'ts-loader' => '~2.1.0',
			'extract-text-webpack-plugin' => '~2.1.2',
			'css-loader' => '~0.28.4',
			'file-loader' => '~0.11.2',
			'less-loader' => '~4.0.4',
			'style-loader' => '~0.18.2',
			'url-loader' => '~0.5.9',
			"webpack-webuilder-resolver" => "^1.0.0",
			"sass-loader" => "^7.1.0",
			"node-sass" => "^4.11.0",
			"precss" => "^4.0.0",
			"autoprefixer" => "^9.4.7",
			"postcss-loader" => "^3.0.0",
		];
	}
	private function config(array $sources){
		$log = log::getInstance();
		$log->debug("looking for webpack.config.js");
		$webpackConfig = $this->getRepository()->getDirectory()->file('webpack.config.js');
		if(!$webpackConfig->exists()){
			$log->reply("Notfound");
			$log->debug("copy original file");
			$original = new file(packages::package('webpack')->getFilePath('storage/private/webpack.config.js.original'));
			$original->copyTo($webpackConfig);
			$log->reply("Success");
		}else{
			$log->reply("Found");
		}
		$webuilderData = array();
		$log->debug("get list of assets");
		foreach($sources as $source){
			$name = $source->getName();
			$assets = $source->getAssets();
			$sourceData = array(
				'name' => $name,
				'path' => realpath($source->getPath()),
				'assets' => []
			);
			if(!isset($webuilderData['entries'][$name])){
				$webuilderData['entries'][$name] = array();
			}
			foreach($assets as $asset){
				if(in_array($asset['type'], ['js', 'css', 'less', 'ts', "sass", "scss"])){
					if(isset($asset['file'])){
						$file = realpath($source->getPath().'/'.$asset['file']);
						if(!$file){
							die($source->getPath().'/'.$asset['file']);
						}
						if(!in_array($file, $webuilderData['entries'][$name])){
							$webuilderData['entries'][$name][] = $file;
						}
					}
				}elseif($asset['type'] == 'package'){
					$sourceData['assets'][] = $asset;
				}
			}
			if(empty($webuilderData['entries'][$name])){
				unset($webuilderData['entries'][$name]);
			}
			$webuilderData['sources'][] = $sourceData;
		}
		$webpackConfig = $this->repositoryDirectory->file('webuilder.json');
		$log->debug("wrting webuilder data to", $webpackConfig->getPath());
		$webpackConfig->write(json\encode($webuilderData, json\PRETTY));
		
	}
	private function runInCommandLine(array $sources){
		$log = log::getInstance();
		$log->debug("get list of handled assets");
		$result = array(
			'handledFiles' => [],
			'outputedFiles' => [],
		);
		foreach($sources as $source){
			$assets = $source->getAssets();
			foreach($assets as $asset){
				if(in_array($asset['type'], ['js', 'css', 'less', 'ts', "sass", "scss"])){
					if(isset($asset['file'])){
						$result['handledFiles'][$source->getName()][] = $source->getPath().'/'.$asset['file'];
					}
				}
			}
		}
		$log->debug("run webpack");
		if(!function_exists('shell_exec')){
			$log->reply()->fatal('no shell access');
			throw new notShellAccess();
		}
		$dist = new directory(packages::package('webpack')->getFilePath('storage/public/frontend/dist'));
		$webpack = $this->repositoryDirectory->file('node_modules/.bin/webpack')->getPath();
		$context = $this->repositoryDirectory->getRealPath().'/';
		$config = $this->repositoryDirectory->file('webpack.config.js')->getRealPath();
		$cmd = "{$webpack} --context={$context} --config={$config} --progress=false --colors=false --json --hide-modules";
		$output = shell_exec($cmd);
		$parsedOutput = json\decode($output);
		if(!$parsedOutput){
			$log->reply()->fatal('cannot parse response');
			throw new WebpackException($cmd, $output);
		}
		if(!isset($parsedOutput['errors']) or !empty($parsedOutput['errors'])){
			$log->reply()->fatal('there is some errors');
			throw new WebpackException($cmd, $output);
		}
		$log->reply("Success");
		$log->debug("save chunk paths");
		foreach($parsedOutput['chunks'] as $chunk){
			foreach($chunk['files'] as $file){
				$file = $dist->file($file);
				if(!$file->exists()){
					throw new WebpackChunkFileException($file->getPath());
				}
				foreach($chunk['names'] as $name){
					$result['outputedFiles'][$name][] = $file->getPath();
				}
			}
		}
		$this->repositoryDirectory->file('result.json')->write(json\encode($result, json\PRETTY));
	}
	public function clean(){
		$this->cleanRepository();
		$this->cleanDistFiles();
	}
	public function cleanSourcesRepository(){
		$repo = new directory(packages::package('base')->getFilePath('storage/private/frontend/src'));
		$repo->delete();
	}
	public function cleanNPMPackages(){
		$modules = new directory(packages::package('base')->getFilePath('storage/private/frontend/node_modules'));
		$modules->delete();
	}
	public function cleanRepository(){
		$repo = new directory(packages::package('base')->getFilePath('storage/private/frontend'));
		$repo->delete();
	}
	public function cleanDistFiles(){
		$repo = new directory(packages::package('base')->getFilePath('storage/public/frontend'));
		$repo->delete();
	}
	private function watchSource(source $source){
		$log = log::getInstance();
		$log->debug("get list of files in source");
		$files = (new directory($source->getPath()))->files(true);
		$log->reply(count($files), "files found");
        foreach($files as $file){
			$path = $file->getPath();
			$log->debug("watch", $path);
			if(strpos($path, 'node_modules') === false){
				if($file->getExtension() != 'php'){
					$watchID = inotify_add_watch($this->inotifyHandler, $path, IN_MODIFY);
					$this->watchFiles[$watchID] = $file;
					if($watchID){
						$log->reply("Success");
					}else{
						$log->reply()->fatal("Failed");
						return false;
					}
				}else{
					$log->reply("skipped, bacause It is php file");
				}
			}else{
				$log->reply("skipped, bacause It is a node module");
			}
        }
		return true;

	}
	public function watch(){
		$log = log::getInstance();
		$log->debug('looking for inotify extension');
		if(extension_loaded('inotify')){
			$log->reply("found");
			$this->prepareAssets();
			$this->inotifyHandler = inotify_init();
			$log->debug("get list of frontend sources");
			$sources = $this->getListOfSources();
			if(!$sources){
				$log->reply("empty");
				$log->debug("nothing to do");
				return true;
			}
			foreach($sources as $source){
				$log->info("watch source", $source->getPath());
				if($this->watchSource($source)){
					$log->reply("success");
				}else{
					$log->reply()->fatal('failed');
					return false;
				}
			}
			$log->info("run webpack wacher");
			$webpack = $this->repo->file('node_modules/.bin/webpack')->getPath();
			$context = $this->repo->getRealPath().'/';
			$config = $this->repo->file('webpack.config.js')->getRealPath();
			$cmd = "{$webpack} --context={$context} --config={$config} --progress=false --colors=false --json --hide-modules --watch > /dev/null 2>&1 &";
			$output = shell_exec($cmd);
			$log->info("watch for file's events");
			while(true){
				$queue = inotify_queue_len($this->inotifyHandler);
				if($queue > 0){
					$events = inotify_read($this->inotifyHandler);
					$log->reply("got", count($events), "events");
					foreach($events as $key => $event){
						$file = $this->watchFiles[$event['wd']];
						$filePath = $file->getPath();
						$log->info("handle #{$key}", $filePath);
						$relativePath = $this->getRelativePathOfSource($filePath);
						$distFile = $this->repo->file('src/'.$relativePath);
						$file->copyTo($distFile);
						$log->reply("copied");
					}
				}else{
					usleep(250000);
				}
			}
		}else{
			$log->reply()->fatal('notfound');
		}
	}
}
class InstallNpmPackageException extends \Exception{
	protected $package;
	public function __construct(string $package){
		$this->package = $package;
		parent::__construct("cannot install {$package} package");
	}
	public function getPackage():string{
		return $this->package;
	}

}
class WebpackException extends \Exception{
	protected $cmd;
	public function __construct(string $cmd, $message){
		$this->cmd = $cmd;
		parent::__construct($message);
	}
	public function getCMD():string{
		return $this->cmd;
	}
}
class WebpackChunkFileException extends \Exception{}
class IncompatibleNPMVersions extends \Exception{
	protected $package1;
	protected $package2;
	public function __construct(array $package1, array $package2){
		//parent::__construct($package1['name']."@".)
		$this->package1 = $package1;
		$this->package2 = $package2;
	}
	public function getPackage1():array{
		return $this->package1;
	}
	public function getPackage2():array{
		return $this->package2;
	}
}