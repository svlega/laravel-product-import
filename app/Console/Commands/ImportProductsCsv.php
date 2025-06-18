<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\_CatalogModule\Models\ProductImport as ModelsProductImport;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\FileUploadConfiguration;
use Livewire\WithFileUploads;
use Lunar\Hub\Http\Livewire\Traits\HasImages;
use Lunar\Models\Brand;
use Lunar\Models\Channel;
use Lunar\Models\Collection;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Spatie\SimpleExcel\SimpleExcelReader;


class ImportProductsCsv extends Command
{
    use WithFileUploads;
    use HasImages;

    public $csvfile;
    public $imageFiles;
    public $product_import;
    public $product;
    public $errors = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-products-csv';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import products csv';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->confirm('Do you wish to delete existing products & import this csv?')) {
            $this->deleteExistingProducts();
        }
        $this->importCsv();
    }



    /**
     * Returns the model which has media associated.
     *
     * @return \Lunar\Models\Product
     */
    protected function getMediaModel()
    {
        return $this->product;
    }

    protected function deleteExistingProducts()
    {
        $products = Product::withTrashed()->get();
        foreach($products as $product){
            $product->customerGroups()->detach();
            $variants = $product->variants->all();
            foreach($variants as $variant){
                $variant->values()->detach();
                $variant->delete();
            }
            $product->collections()->detach();
            $product->forceDelete();
        }
    }


    public function importCsv()
    {
        try{
          $file_path = 'database/seeders/catalog-module/product_import.csv';
          if (file_exists($file_path)) {
             
            $rows = SimpleExcelReader::create($file_path,'csv')->getRows();

            $file_name = 'product_import.csv';

            //Create import log entry
            $this->product_import = new ModelsProductImport();
            $this->product_import->file_name = $file_name;
            $this->product_import->file_path = $file_path;
            $this->product_import->total_rows=$rows->count();
            $this->product_import->imported_rows=0;
            $this->product_import->status = 'pending';
            $this->product_import->save();

            //Import products
            SimpleExcelReader::create($file_path,'csv')->getRows()->each(function(array $rowProperties, $rowIndex) {
                try{
                // process the row
                $sku = $rowProperties['sku'];
                $product_type = $rowProperties['product_type'];
                $brand = $rowProperties['brand'];
                $collection = $rowProperties['collection'];
                $product_name_translations = [];
                $product_description_translations = [];
                $product_nez_translations = [];
                $product_bouche_translations = [];
                $product_accords_metsvin_translations = [];
                $languages = Language::get();
                foreach($languages as $language){
                    $product_name_translations[$language->code] = new \Lunar\FieldTypes\Text($rowProperties['name']);
                    $product_description_translations[$language->code] = new \Lunar\FieldTypes\Text($rowProperties['description']);
                    $product_nez_translations[$language->code] = new \Lunar\FieldTypes\Text($rowProperties['nez']);
                    $product_bouche_translations[$language->code] = new \Lunar\FieldTypes\Text($rowProperties['bouche']);
                    $product_accords_metsvin_translations[$language->code] = new \Lunar\FieldTypes\Text($rowProperties['accords_metsvin']);
                }
                

                // Product variant
                $volumes = explode(", ", $rowProperties['volume']);
                $product_option_values = [];
                $product_option_skus=[];
                $product_option_prices=[];
                $product_option_qty=[];
                $volume_attribute_value = [];
                if(count($volumes)> 1){
                    foreach($volumes as $volume){
                        $volumeData = explode(":", $volume);
                        $volume = $volumeData[0];
                        $sku = $volumeData[1];
                        $price = $volumeData[2];
                        $qty = $volumeData[3];
                        $volume_attribute_value[]=$volume;
                        $product_option_value = ProductOptionValue::where('name','LIKE',"%{$volume}%")->first();
                        $product_option_values[]=$product_option_value->id;
                        $product_option_skus[] = $sku;
                        $product_option_prices[] = $price;
                        $product_option_qty[] = $qty;
                    }
                }else{ // to set default product option
                    $volume = $rowProperties['volume'];
                    $price = $rowProperties['price'];
                    $qty = $rowProperties['qty'];
                    $volume_attribute_value[]=$volume;
                    $product_option_value = ProductOptionValue::where('name','LIKE',"%{$volume}%")->first();
                    $product_option_values[]=$product_option_value->id;
                    $product_option_skus[] = $sku;
                    $product_option_prices[] = $price;
                    $product_option_qty[] = $qty;
                }

                $attribute_data = collect([
                    'name' => new \Lunar\FieldTypes\TranslatedText(collect($product_name_translations)),
                    'description' => new \Lunar\FieldTypes\TranslatedText(collect($product_description_translations)),
                    'couleur'=> new \Lunar\FieldTypes\Dropdown($rowProperties['couleur']),
                    'region'=> new \Lunar\FieldTypes\Dropdown($rowProperties['region']),
                    'appellation'=> new \Lunar\FieldTypes\Dropdown($rowProperties['appellation']),
                    'volume'=> new \Lunar\FieldTypes\ListField($volume_attribute_value),
                    'cepages'=> new \Lunar\FieldTypes\Text($rowProperties['cepages']),
                    'nez'=> new \Lunar\FieldTypes\TranslatedText(collect($product_nez_translations)),
                    'bouche'=> new \Lunar\FieldTypes\TranslatedText(collect($product_bouche_translations)),
                    'accords_metsvin'=> new \Lunar\FieldTypes\TranslatedText(collect($product_accords_metsvin_translations))
                ]);

                $product_variant = ProductVariant::where('sku',$sku)->first();
                $existing_product_type = ProductType::where('name', $product_type)->first();
                $existing_brand = Brand::where('name', 'no_brand')->first();

                $product_data = [
                'status' => $rowProperties['status'],
                'product_type_id' => $existing_product_type->id,
                'brand_id' => $existing_brand->id,
                'attribute_data'=>$attribute_data
                ];

                $product_variant_data = [
                'purchasable' => 'in_stock',
                'tax_class_id' => TaxClass::getDefault()?->id,
                'shippable' => true,
                'stock' => $rowProperties['qty'],
                'unit_quantity' => 1,
                'backorder' => 0,
                'sku'=>$sku
                ];

                $price_data = [
                'price' => $rowProperties['price'],
                'currency_id'=>Currency::getDefault()->id
                ];



                if($product_variant){ //Existing Product
                    $this->product = $product_variant->product;
                    $this->product->update($product_data);

                    $this->updateChannelsAvailability($this->product, $rowProperties);
                    $product_variant_data['product_id'] = $this->product->id;

                    //product variant
                    if(count($volumes)> 1){
                        foreach ($product_option_values as $key => $optionsToCreate) {
                            $product_variant_data['sku'] = $product_option_skus[$key];
                            $product_variant_data['stock'] = $product_option_qty[$key];
                            $price_data['price'] = $product_option_prices[$key];

                            $variant = ProductVariant::where('sku',$product_option_skus[$key])->first();
                            $variant->update($product_variant_data);
                            //$variant->values()->attach($optionsToCreate);
                            $variant->prices()->first()->update($price_data);
                        }
                    }
                    else{
                     $product_variant->update($product_variant_data);
                     $product_variant->prices()->first()->update($price_data);
                    }

                }else{ //New Product

                    $this->product = new Product($product_data);
                    $this->product->save();  

                    $this->updateChannelsAvailability($this->product,$rowProperties);  

                    $product_variant_data['product_id'] = $this->product->id;

                    /*
                    // Without default product option for the product variant 
                    //product variant
                    if(count($volumes)> 1){
                        $permutations = $this->getPermutations($product_option_values);
                        foreach ($permutations as $key => $optionsToCreate) {
                            $product_variant_data['sku'] = $product_option_skus[$key];
                            $product_variant_data['stock'] = $product_option_qty[$key];
                            $price_data['price'] = $product_option_prices[$key];

                            $variant = new ProductVariant($product_variant_data);
                            $variant->save();
                            $variant->values()->attach($optionsToCreate);
                            $variant->prices()->create($price_data);
                        }
                    }
                    else{

                        
                        $product_variant = new ProductVariant($product_variant_data);
                        $product_variant->save();
                        $product_variant->prices()->create($price_data);
                    }*/

                    //Product variant with product options
                    foreach ($product_option_values as $key => $optionsToCreate) {
                        $product_variant_data['sku'] = $product_option_skus[$key];
                        $product_variant_data['stock'] = $product_option_qty[$key];
                        $price_data['price'] = $product_option_prices[$key];

                        $variant = new ProductVariant($product_variant_data);
                        $variant->save();
                        $variant->values()->attach($optionsToCreate);
                        $variant->prices()->create($price_data);
                    }
                    

                }

                //Collection 
                $collectionIds = Collection::where('attribute_data','LIKE','%'.$brand.'%')
                                ->orWhere('attribute_data','LIKE','%'.$collection.'%')
                                ->pluck('id');
                $this->product->collections()
                        ->sync($collectionIds);

                //Media
                /*
                $filename = $rowProperties['media_file'];
                $mediaFilePath = Storage::path('media/'.$rowProperties['media_file']);
                // Need to find any images that have been deleted.
                // We need to also get a fresh instance of the relationship
                // as we may have changes that Livewire/Eloquent might not be aware of.
                $existingMediaImages = $this->product->refresh()->getMedia('images');
                foreach($existingMediaImages as $media){
                    $media->forceDelete();
                }
                
                if (FileUploadConfiguration::isUsingS3()) {
                    $media = $this->product->addMediaFromDisk($mediaFilePath)
                        ->preservingOriginal()
                        ->usingFileName($filename)
                        ->toMediaCollection('images');
                } else {
                    $media = $this->product->addMedia($mediaFilePath)
                        ->preservingOriginal()
                        ->usingFileName($filename)
                        ->toMediaCollection('images');
                }
                $media->setCustomProperty('caption', $rowProperties['name']);
                $media->setCustomProperty('primary', true);
                $media->setCustomProperty('position',1);
                $media->save();
                */


                //Update imported file status
                $this->product_import->imported_rows=$this->product_import->imported_rows + 1;
                if($this->product_import->total_rows === $this->product_import->imported_rows)
                $this->product_import->status = 'complete';
                $this->product_import->save();
                }
                catch(Exception $e){
                    $excel_row_error_message = 'Line:'.($rowIndex+2).'-'.$e->getMessage();
                    array_push($this->errors,$excel_row_error_message);
                    Log::error($e->getMessage());
                    Log::error($e->getTraceAsString());
                }
            });
            }
            
            else{
                echo "CSV file not found";
                return false;
            } 
        }
        catch(Exception $e){
            array_push($this->errors,$e->getMessage());
            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());
        }
        foreach($this->errors as $error){
            echo "\r\n"; 
            echo $error;
        }
        
    } 

    public function updateChannelsAvailability($product, $rowProperties){
        //Channels
        $channel_id = Channel::first()->id;
        $channels = [];
        $channels[$channel_id] = [
            'starts_at' => null,
            'ends_at' => null,
            'enabled' => true
        ];

        //CustomerGroups
        $customer_group_id = CustomerGroup::first()->id;
        $cgAvailability = [];
        $visible = 1;
        $purchasable=1;
        if(array_key_exists('visible',$rowProperties) && is_int($rowProperties['visible']))
           $visible = $rowProperties['visible'];
        if(array_key_exists('purchasable',$rowProperties) && is_int($rowProperties['purchasable']))
           $purchasable = $rowProperties['purchasable'];
        
        $cgAvailability[$customer_group_id] = [
            'starts_at' => null,
            'ends_at' => null,
            'enabled' => true,
            'visible'=>$visible,
            'purchasable'=>$purchasable
        ];

        
       //Customer Group & Channel
       $product->channels()->sync(collect($channels));
       $product->customerGroups()->sync(collect($cgAvailability));
   }
    
}
