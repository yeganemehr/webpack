<?php
namespace packages\webpack\listeners;
use \packages\base\packages;
use \packages\base\json;
use \packages\base\IO\file;
use \packages\base\IO\directory;
use \packages\base\frontend\theme;
use \packages\base\view\events\beforeLoad;
class base{
	public function beforeLoadView(beforeLoad $event){
		$view = $event->getView();
		$sources = theme::byName($view->getSource()->getName());
		$view->clearAssets();
		foreach($this->checkAssetsForWebpack($sources) as $asset){
			if($asset['type'] == 'css'){
				if(isset($asset['file'])){
					$view->addCSSFile($asset['file'], isset($asset['name']) ? $asset['name'] : '');
				}elseif(isset($asset['inline'])){
					$view->addCSS($asset['inline'], isset($asset['name']) ? $asset['name'] : '');
				}
			}elseif($asset['type'] == 'js'){
				if(isset($asset['file'])){
					$view->addJSFile($asset['file'], isset($asset['name']) ? $asset['name'] : '');
				}elseif(isset($asset['inline'])){
					$view->addJS($asset['inline'], isset($asset['name']) ? $asset['name'] : '');
				}
			}
		}
	}
	public function checkAssetsForWebpack(array $sources):array{
		$result = $this->getWebpackResult();
		$filteredAssets = [];
		$filteredFiles = [];
		$commonAssets = [];
		if(isset($result['outputedFiles']['common'])){
			foreach($result['outputedFiles']['common'] as $file){
				$file = new file\local($file);
				$commonAssets[] = array(
					'type' => $file->getExtension(),
					'file' => "/".$file->getPath()
				);
				$filteredFiles[] = $file->getPath();
			}
		}
		foreach($sources as $source){
			$handledFiles = [];
			$name = $source->getName();
			$assets = $source->getAssets();
			if(isset($result['handledFiles'][$name])){
				$handledFiles = $result['handledFiles'][$name];
				if($commonAssets){
					$filteredAssets = array_merge($filteredAssets, $commonAssets);
					$commonAssets = [];
				}
			}

			if(isset($result['outputedFiles'][$name])){
				foreach($result['outputedFiles'][$name] as $file){
					$file = new file\local($file);
					if(!in_array($file->getPath(), $filteredFiles)){
						$filteredAssets[] = array(
							'type' => $file->getExtension(),
							'file' => "/".$file->getPath()
						);
						$filteredFiles[] = $file->getPath();
					}
				}
			}
			foreach($assets as $asset){
				if(in_array($asset['type'], ['js', 'css', 'less', 'ts'])){
					if(isset($asset['file'])){
						if(!in_array($source->getPath().'/'.$asset['file'], $handledFiles)){
							$asset['file'] = $source->url($asset['file']);
							$filteredAssets[] = $asset;
						}
					}else{
						$filteredAssets[] = $asset;
					}
				}
			}
		}
		if($filteredFiles){
			
		}
		return $filteredAssets;
	}
	private function getWebpackResult():array{
		$privateRepo = new directory\local(packages::package('webpack')->getFilePath('storage/private/frontend'));
		$result = array();
		$resultFile = $privateRepo->file('result.json');
		if($resultFile->exists()){
			$result = json\decode($resultFile->read());
		}
		if(!is_array($result)){
			$result = array();
		}
		if(!isset($result['handledFiles'])){
			$result['handledFiles'] = [];
		}
		if(!isset($result['outputedFiles'])){
			$result['outputedFiles'] = [];
		}
		return $result;
	}
}
