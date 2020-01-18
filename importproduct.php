<?php
define('DS', DIRECTORY_SEPARATOR); 
use \Magento\Framework\App\Bootstrap;

include('../../app/bootstrap.php');
$bootstrap = Bootstrap::create(BP, $_SERVER);

error_reporting(E_ALL);
ini_set('display_errors', 1);

$objectManager = $bootstrap->getObjectManager();

$app_state = $objectManager->get('\Magento\Framework\App\State');
$app_state->setAreaCode('frontend');
$filename = "import.csv";


$import = new Import();
$collection = $import->getCsvToArray($filename);
//echo '<pre>';print_r($collection);
foreach($collection as $product){
	
	$product = $import->validateData($product);
	if($product['type'] == "simple"){
		$_product = $import->createSimpleProduct($product);
	}
	
	if($product['type'] == "configurable"){
		$_product = $import->createConfigurableProducts($product);
		
	}
	
	if($product['type'] == "grouped"){
		$_product = $import->createGroupedProducts($product);
	}
	echo $_product->getName().' -- '.$_product->getSku().' -- Successfully Created';
	echo "<br/>";
}

echo "Product Successfully Imported";

echo "<br/>Please wait.. while we reindex the data";
$import->reIndexing();
class Import{
	
	/**
	*	Convert csv into array
	*
	*/
	
	public function getCsvToArray($filename='', $delimiter=',')
	{
		if(!file_exists($filename) || !is_readable($filename))
			return FALSE;

		$header = NULL;
		$data = array();
		if (($handle = fopen($filename, 'r')) !== FALSE)
		{
			while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE)
			{
				if(!$header)
					$header = $row;
				else
					$data[] = array_combine($header, $row);
			}
			fclose($handle);
		}
		return $data;
		
	}
	
	public function createSimpleProduct($data){
		if(isset($data['url_key'])){
			unset($data['url_key']);
		}
		
		//$data = $this->checkProductAttribute($data);
		$product = $this->ob('\Magento\Catalog\Model\Product');
		
		$urlKey = $this->getUrlKey($data);
		if($this->getIdBySku($data['sku'])) {
			$_product = $this->ob("\Magento\Catalog\Api\ProductRepositoryInterface")->get($data['sku']);
			$_product = $this->addProductData($_product,$data);
			$_product->setDescription($data['description']);
			$_product->setShortDescription($data['short_description']);
			$this->updateImages($_product,$data);
			$_product->save();
			return $_product;
		}
		else{
			$product->setSku($data['sku'])
					->setName($data['name'])
					->setAttributeSetId($this->getAttributeSetId($data['attribute_set']))
					->setWeight($data['weight']) // weight of product
					->setVisibility($data['visibility'])
					->setTaxClassId($data['tax_class_id']) // Tax class id
					->setTypeId($data['type']) // type of product (simple/virtual/downloadable/configurable)
					->setUrlKey($urlKey)
					->setStatus(1)
					->setWebsiteIds(array($this->getWebsiteId()));
			$product->setDescription($data['description']);
			$product->setShortDescription($data['short_description']);
			try{
				
				$product->save();
				
				$images = array('image' => $data['image'],'small_image' => $data['small_image'],'thumbnail' => $data['thumbnail']);
				if (($data['image'] == $data['small_image']) && ($data['small_image'] == $data['thumbnail'])) {
					
					$keys = explode(",",'image,small_image,thumbnail');
					$this->addImage($product,$data['image'],$keys);
				}else{
					foreach($images as $key => $value){
						$this->addImage($product,$value,array($key));
					}
				}
				
				if(!empty($data['images'])){
					$galleryImages = explode(",",$data['images']);
					foreach($galleryImages as $image){
						$this->addImage($product,$image,array());
					}
				}
				
			}catch(\Exception $ex){
				echo $ex->getMessage();
			}
			
			$this->addProductData($product,$data);
		
		}
		//die;
		return $product;
	}
	
	public function updateImages($product,$data){
		if($data['image']){
			$imageProcessor = $this->ob('\Magento\Catalog\Model\Product\Gallery\Processor');
			$images = $product->getMediaGalleryImages();
			foreach($images as $child){
				$imageProcessor->removeImage($product, $child->getFile());
			}
		}
		$images = array('image' => $data['image'],'small_image' => $data['small_image'],'thumbnail' => $data['thumbnail']);
		if (($data['image'] == $data['small_image']) && ($data['small_image'] == $data['thumbnail'])) {
			$keys = explode(",",'image,small_image,thumbnail');
			$this->addImage($product,$data['image'],$keys);
		}else{
			foreach($images as $key => $value){
				$this->addImage($product,$value,array($key));
			}
		}
		
		if(!empty($data['images'])){
			$galleryImages = explode(",",$data['images']);
			foreach($galleryImages as $image){
				$this->addImage($product,$image,array());
			}
		}
	}
	
	public function getUrlKey($data){
		$url = preg_replace('#[^0-9a-z]+#i', '-', $data['name']);
		$urlKey = strtolower($url);
		$time = strtotime("now"); 
		$storeManager = $this->ob('\Magento\Store\Model\StoreManagerInterface');
		$storeId = (int) $storeManager->getStore()->getStoreId();
		$isUnique = $this->checkUrlKeyDuplicates($data['sku'], $urlKey,$storeId);
		if ($isUnique) {
			return $urlKey;
		} else {
			return $urlKey . '-' . time();
		}
	}
	
	/*
	 * Function to check URL Key Duplicates in Database
	 */

	private function checkUrlKeyDuplicates($sku, $urlKey, $storeId) 
	{
		$urlKey .= '.html';
		$Resource = $this->ob('\Magento\Framework\App\ResourceConnection');
		$connection = $Resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);

		$tablename = $connection->getTableName('url_rewrite');
		$sql = $connection->select()->from(
						['url_rewrite' => $connection->getTableName('url_rewrite')], ['request_path', 'store_id']
				)->joinLeft(
						['cpe' => $connection->getTableName('catalog_product_entity')], "cpe.entity_id = url_rewrite.entity_id"
				)->where('request_path IN (?)', $urlKey)
				->where('store_id IN (?)', $storeId)
				->where('cpe.sku not in (?)', $sku);

		$urlKeyDuplicates = $connection->fetchAssoc($sql);

		if (!empty($urlKeyDuplicates)) {
			return false;
		} else {
			return true;
		}
	}
	
	public function addProductData($product,$data){
		$productRepository = $this->ob('\Magento\Catalog\Api\ProductRepositoryInterface');
		
		$product->setPrice($data['price'])
				->setStatus($this->getStatus($data))
				->setStockData(
					array(
						'use_config_manage_stock' => 0,
						'manage_stock' => 1,
						'is_in_stock' => $data['is_in_stock'],
						'qty' => $data['qty']
					)
				);
		$custom_attr = $data;
		$custom_attr = $this->ignoreDefaultAttributes($custom_attr);
		$categoryIds = explode(",",$data['category_ids']);
		$product->setCategoryIds($categoryIds);
		$product->setDescription($data['description']);
		$product->setShortDescription($data['short_description']);
		
		$systemAttr = array('meta_description','meta_keyword','meta_title','description','short_description');
		
		$productResource = $this->ob("\Magento\Catalog\Model\ResourceModel\Product");
		foreach($custom_attr as $key => $value){
			
			$keys = explode("_",$key);
			$attrkey = "";
			foreach($keys as $k){
				$attrkey .= ucfirst($k);
			}
			$verb = "set".$attrkey;
			$product->$verb($data[$key]);
			$product->setData($key,$value);
		}
		try{
			
			$product->save();
		}catch(\Exception $ex){
			echo $ex->getMessage();
		}
		
		foreach($custom_attr as $key => $value){
			
			$type = $this->getAttributeType($key);
			if($type == "select" || $type == "swatch_visual" || $type == "swatch_text" ){
				
				$attribute = $this->getAttribute($key);
				$results = $this->getOptionIdFromValue($value);
				
				$attributeOptionId = "";
				if($key == "maat"){
					if(count($results) > 0){
						foreach($results as $optionData){
							
							$attributeOptions = $this->getAttributeOptionIds($attribute->getId(),$optionData['option_id']);
							if(count($attributeOptions) > 0){
								$attributeOptionId = $optionData['option_id'];
							}
							
						}
					}
				}else{
					$attributeOptionId = $attribute->getSource()->getOptionId($value);
				}
				
				if(!$attributeOptionId){
					if($value){
						$attributeOptionId = $this->addAttributeOption($key,$value);
					}
				}
				try{
					$this->ob('\Magento\Catalog\Model\ResourceModel\Product\Action')->updateAttributes([$product->getId()], array($key => $attributeOptionId), 0);
					
					$product->setData($key,$attributeOptionId)->save();
				}catch(\Exception $ex){
					
				}
			}
			
		}
		$product->save();
		return $product;
	}
	
	public function validateData($data){
		if($data['visibility'] == "Catalog, Search"){
			$data['visibility'] = 4;
		}else{
			$data['visibility'] = 1;
		}
		
		if($data['status'] == "Enabled"){
			$data['status'] = 1;
		}else{
			$data['status'] = 0;
		}
		return $data;
	}
	
	public function getStatus($data){
		if($data['status'] == "Ingeschakeld"){
			return true;
		}
		
		if($data['status'] == "enabled"){
			return true;
		}
		
		if($data['status'] == 1){
			return true;
		}
		
		return false;
	}
	
	public function getAttributeSetId($attrSetName)
	{
		$_attributeSetCollection = $this->ob("\Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory");
		
		$attributeSet = $_attributeSetCollection->create()->addFieldToSelect(
                    '*'
                    )->addFieldToFilter(
                            'attribute_set_name',
                            $attrSetName
                    )->addFieldToFilter(
                            'entity_type_id',
                            4
                    );
		
		
        foreach($attributeSet as $attr){
            $attributeSetId = $attr->getAttributeSetId();
        }
		
        return $attributeSetId;
	}
	
	
	
	public function createConfigurableProducts($data){
		$prod = $this->ob('\Magento\Catalog\Model\Product');
		//$data['type'] = "simple";
		$product = $this->createSimpleProduct($data);
		$productId = $product->getId(); // Configurable Product Id
		
		$attributeModel = $this->ob('Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute');
		$position = 0;
		$attributes = explode(",",$data['configurable_attributes']); // Super Attribute Ids Used To Create Configurable Product
		$associatedProductIds = explode(",",$data['associated_products']); //Product Ids Of Associated Products
		foreach($associatedProductIds as $sku){
			$ids[] = $this->getIdBySku($sku);
		}
		
		
		foreach ($attributes as $attrCode) {
			$attributeIds[] = $this->getAttributeId($attrCode);
		}
		$this->associateConfigProducts($ids,$product,$attributeIds,$data);
		
		return $product;
	}
	
	public function associateConfigProducts($associatedProductIds,$product,$attributes,$data){
		
		//$product = $this->ob('\Magento\Catalog\Model\Product')->load($product->getId());
		/* Associate simple product to configurable */
		
		$attributeModel = $this->ob('Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute');
		$position = 0;
		
		$attributeSetId = $this->getAttributeSetId($data['attribute_set']);
		
		$product->setTypeId("configurable");
		$product->setAffectConfigurableProductAttributes($attributeSetId);
		try{
			$this->ob('Magento\ConfigurableProduct\Model\Product\Type\Configurable')->setUsedProductAttributeIds($attributes, $product);
		}catch(\Exception $ex){
			
		}
		$product->setCanSaveConfigurableAttributes(true);
		
		foreach ($attributes as $attributeId) {
			$attrdata = array('attribute_id' => $attributeId, 'product_id' => $product->getId(), 'position' => $position);
			$position++;
			try{
				$attributeModel->setData($attrdata)->save();
			}catch(\Exception $ex){
				
			}
			
		}
		$product->setNewVariationsAttributeSetId($attributeSetId);
		
		$product->setAssociatedProductIds($associatedProductIds);
		try{
			$product->save();
		}catch(\Exception $ex){
			echo $ex->getMessage();
		}
		return $product;
	}
	
	public function createGroupedProducts($data){
		//$data['type'] = "simple";
		$product = $this->createSimpleProduct($data);
		
		$productId = $product->getId(); 
		
		$associatedProductSkus = explode(",",$data['grouped_product_sku']);
		
		$this->addLinksToProduct($associatedProductSkus,$product);
		
		/* //set product type
		//$product->setTypeId("grouped");
		//$product->save();
		print_r($associatedProductIds);
		foreach($associatedProductIds as $sku){
			$childrenIds[] = $this->getIdBySku($sku);
		}
		
		$_product = $this->ob('\Magento\Catalog\Api\ProductRepositoryInterface')->getById($product->getId());
		$this->associateGroupProducts($childrenIds,$_product);
		try{
			$product->save();
		}
		catch(\Exception $ex){
			echo $ex->getMessage();
		}
		die; */
		return $product;
	}
	
	public function addLinksToProduct($associatedProductSkus, $product) {
		$links = array();
		$position = 0;
		//print_R($associatedProductSkus);die;
		foreach ($associatedProductSkus as $key => $sku) {
			$position++;
			//echo $sku;die;
			$linkedProduct = $this->getProdBySku($sku);
			if($linkedProduct){
			$productLinkFactory = $this->ob('\Magento\Catalog\Model\ProductLink\LinkFactory');
			$link = $productLinkFactory->create()
				->setSku($product->getSku())
				->setLinkedProductSku($sku)
				->setPosition($position)
				->setLinkType('associated');
			$link
				->getExtensionAttributes()
				->setQty($linkedProduct->getQty());
			$links[] = $link;
			}
		}
		$product->setProductLinks($links);

		$product->save();
		//die;
	}
	
	public function associateGroupProducts($childrenIds,$product){
		$associated = array();
		$position = 0;
		
		foreach($childrenIds as $productId){
		   $position++;
		   //You need to load each product to get what you need in order to build $productLink
		   $linkedProduct = $this->getProdById($productId);
		   
		   /** @var \Magento\Catalog\Api\Data\ProductLinkInterface $productLink */
		   $productLink = $this->ob('\Magento\Catalog\Api\Data\ProductLinkInterface');
		   $productLink->setSku($product->getSku()) //sku of product group
			   ->setLinkType('associated')
			   ->setLinkedProductSku($linkedProduct->getSku())
			   ->setLinkedProductType($linkedProduct->getTypeId())
			   ->setPosition($position)
			   ->getExtensionAttributes()
			   ->setQty($linkedProduct->getQty());
		   $associated[$productId] = $productLink;
		   
		}
		$product->setProductLinks($associated);
		$product->setGroupedLinkData($associated); 
		$productRepository = $this->ob('\Magento\Catalog\Api\ProductRepositoryInterface');
		$productRepository->save($product);
	}
	
	
	
	public function addImage($product,$image,$type){
		if($image){
			// Adding Image to product
			$imagePath = "import".$image; // path of the image
			$imageRootPath = getcwd().'/../media/'.$imagePath;
			if(file_exists($imageRootPath)){
				//echo '---'.$imagePath;die;
				$product->addImageToMediaGallery($imagePath, $type, false, false);
				$product->save();
			}
		}
	}
	
	public function ob($cl){
		$oM = \Magento\Framework\App\ObjectManager::getInstance();
		return $oM->create($cl); 
	}
	
	public function getAttributeType($key){
		$attribute = $this->ob('\Magento\Eav\Model\Entity\Attribute')->loadByCode('catalog_product', $key);
		if($attribute->getId()){
			return $attribute->getFrontendInput();
		}
	}
	
	public function checkProductAttribute($attrList){
		foreach($attrList as $key => $value){
			//echo $key;
			if(!in_array($key,$this->getDefaultAttributeList())){
				try{
					$attribute = $this->ob('\Magento\Eav\Model\Entity\Attribute')->loadByCode('catalog_product', $key);
					if($attribute->getId()){
						
						if($attribute->getFrontendInput() == "select" || $attribute->getFrontendInput() == "swatch_visual" || $attribute->getFrontendInput() == "swatch_text"){
							
							if($value){
								echo "<br/>".$key.' - '.$attribute->getFrontendInput().'--'.$value;
								$attributeOptionId = $attribute->getSource()->getOptionId($value);
								if(!$attributeOptionId){
									$attributeOptionId = $this->addAttributeOption($key,$value);
								}
								$attrList[$key] = $attributeOptionId;
							}
						}
					}
				}catch(\Exception $ex){
					//echo $ex->getMessage();
					continue;
				}
			}
			
		}
		//die;
		return $attrList;
	}
	
	public function getDefaultAttributeList(){
		/* return array(
				'store','websites','attribute_set','category_ids','type','sku','name','price','description','short_description','image','small_image','thumbnail','weight','has_options','is_in_stock','qty','manage_stock','status','options_container','tax_class_id','visibility','images','page_layout','qty_increments','grouped_product_sku','associatedProductIds','associated_products'
		); */
		
		return array(
				'store','websites','url_key','attribute_set','category_ids','type','sku','image','small_image','thumbnail','weight','has_options','is_in_stock','qty','manage_stock','status','options_container','tax_class_id','visibility','images','page_layout','qty_increments','grouped_product_sku','associatedProductIds','associated_products','configurable_attributes','name','price'
		);
	}
	
	public function ignoreDefaultAttributes($attributes){
		foreach($attributes as $key => $value){
			if(in_array($key,$this->getDefaultAttributeList())){
				unset($attributes[$key]);
			}
		}
		return $attributes;
	}
	
	public function addAttributeOption($attributeCode, $value)
	{
		
        $options  = array(
		
				$value
				);

		/*** Magento\Eav\Setup\EavSetup  */
		try{
		$eavSetupFactory = $this->ob('Magento\Eav\Setup\EavSetupFactory');
        $eavSetupFactory->create()
            ->addAttributeOption(
                [
                    'values' => $options,
                    'attribute_id' => $this->getAttributeId($attributeCode),
					'swatchtext' => $options
                ]
            );
		}catch(\Exception $ex){
			echo $ex->getMessage();
		}
		
		//echo $attributeCode.'--'.$value;die;
		// Get the inserted ID. Should be returned from the installer, but it isn't.
		$attribute = $this->ob('\Magento\Eav\Model\Config')->getAttribute('catalog_product', $attributeCode);
		
		//$this->setProperOptionsArray($attribute);
		$optionId = $attribute->getSource()->getOptionId($value);
		return $optionId; 
	}
	
	public function getIdBySku($sku){
		$product = $this->ob('\Magento\Catalog\Model\Product');
		return $product->getIdBySku($sku);
	}
	
	public function getProdById($id){
		$product = $this->ob('\Magento\Catalog\Model\ProductFactory');
		return $product->create()->load($id);
	}
	
	public function getProdBySku($sku){
		if($this->getIdBySku($sku)){
			$_product = $this->ob('\Magento\Catalog\Api\ProductRepositoryInterface');
			return $_product->get($sku);
		}
	}
	
	public function getAttributeId($code){
		$attribute = $this->ob('\Magento\Catalog\Api\ProductAttributeRepositoryInterface')->get($code);
		return $attribute->getAttributeId();
	}
	
	public function getAttribute($code){
		$attribute = $this->ob('\Magento\Catalog\Api\ProductAttributeRepositoryInterface')->get($code);
		return $attribute;
	}
	
	public function getWebsiteId(){
		$storeManager = $this->ob('\Magento\Store\Model\StoreManagerInterface');
		return $storeManager->getDefaultStoreView()->getWebsiteId();
	}
	
	//use Magento\Indexer\Console\Command\IndexerReindexCommand;
	public function reIndexing()
	{
		$obj                      = \Magento\Framework\App\ObjectManager::getInstance();
		$indexerCollectionFactory = $obj->get("\Magento\Indexer\Model\Indexer\CollectionFactory");
		$indexerFactory           = $obj->get("\Magento\Indexer\Model\IndexerFactory");
		$indexerCollection        = $indexerCollectionFactory->create();
		$allIds                   = $indexerCollection->getAllIds();

		foreach ($allIds as $id)
		{
			shell_exec("php bin/magento indexer:reindex $id");
			echo $id;
			echo "<br/>";
			//$indexer = $indexerFactory->create()->load($id);
			//$indexer->reindexAll(); // this reindexes all
		}
		
		
		
		echo "<br/>Reindexing Successfully Done.";
	}
	
	/**
     * {@inheritdoc} 
     */
    protected function setProperOptionsArray(Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute)
    {
        $canReplace = false;
		$swatchHelper = $this->ob('Magento\Swatches\Helper\Data');
		$swatchHelper->isVisualSwatch($attribute);
        if ($swatchHelper->isVisualSwatch($attribute)) {
            $canReplace = true;
            $defaultValue = $attribute->getData('defaultvisual');
            $optionsArray = $attribute->getData('optionvisual');
            $swatchesArray = $attribute->getData('swatchvisual');
        } elseif($swatchHelper->isTextSwatch($attribute)) {
            $canReplace = true;
            $defaultValue = $attribute->getData('defaulttext');
            $optionsArray = $attribute->getData('optiontext');
            $swatchesArray = $attribute->getData('swatchtext');
        }
        if ($canReplace == true) {
            if (!empty($optionsArray)) {
                $attribute->setData('option', $optionsArray);
            }
            if (!empty($defaultValue)) {
                $attribute->setData('default', $defaultValue);
            } else {
                $attribute->setData('default', [0 => $attribute->getDefaultValue()]);
            }
            if (!empty($swatchesArray)) {
                $attribute->setData('swatch', $swatchesArray);
            }
        }
    }
	
	/**
     * Get Table name using direct query
     */
    public function getTablename($tableName)
    {
        /* Create Connection */
		$resourceConnection = $this->ob('\Magento\Framework\App\ResourceConnection');
        $connection  = $resourceConnection->getConnection();
        $tableName   = $connection->getTableName($tableName);
        return $tableName;
    }
	
	public function getAttributeOptionIds($attrId,$optionId){
		
		$resourceConnection = $this->ob('\Magento\Framework\App\ResourceConnection');
		$tableName = $this->getTableName('eav_attribute_option');
		$query = "SELECT * FROM $tableName where attribute_id = $attrId AND option_id = $optionId";
		/**
		 * Execute the query and store the results in $results variable
		 */
		try{
			$results = $resourceConnection->getConnection()->fetchAll($query);
		}catch(\Exception $ex){
			$results = array();
		}
		return $results;
	}
	
	public function getOptionIdFromValue($value){
		
		$resourceConnection = $this->ob('\Magento\Framework\App\ResourceConnection');
		$tableName = $this->getTableName('eav_attribute_option_value');
		$query = "SELECT * FROM $tableName where value = '$value'";
		/**
		 * Execute the query and store the results in $results variable
		 */
		try{
			$results = $resourceConnection->getConnection()->fetchAll($query);
		}catch(\Exception $ex){
			$results = array();
		}
		return $results;
		//echo "<pre>";print_r($results);
	}
}