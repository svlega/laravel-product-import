<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Exception;
use Illuminate\Support\Facades\Log;
use Livewire\WithFileUploads;
use Lunar\Hub\Http\Livewire\Traits\HasImages;
use Lunar\Models\ProductVariant;
use Spatie\SimpleExcel\SimpleExcelReader;
use Illuminate\Support\Facades\Storage;
use Livewire\FileUploadConfiguration;

class ImportImages extends Command
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
    protected $signature = 'app:import-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import images csv';


    /**
     * Returns the model which has media associated.
     *
     * @return \Lunar\Models\Product
     */
    protected function getMediaModel()
    {
        return $this->product;
    }


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->importImagesCsv();
    }


    public function importImagesCsv()
    {
        try{
          $file_path = 'database/seeders/catalog-module/product_import_images.csv';
          if (file_exists($file_path)) {
             

            //Import products
            SimpleExcelReader::create($file_path,'csv')->getRows()->each(function(array $rowProperties, $rowIndex) {
                try{
                // process the row
                $sku = $rowProperties['sku'];
               

                $product_variant = ProductVariant::where('sku',$sku)->first();
               


                if($product_variant){ //Existing Product
                    $this->product = $product_variant->product;
                    

                //Media
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
                $media->setCustomProperty('caption', $rowProperties['sku']);
                $media->setCustomProperty('primary', true);
                $media->setCustomProperty('position',1);
                $media->save();

                }
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
}
