<?php

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Database\Seeder;

class LensTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $singleVission = ['Zeiss Clearview 1.6 DuraVision Chrome UV' ,
            'Zeiss Clearview 1.5 Photofusion X Grey Duravision Chrome UV',
            'Zeiss Clearview 1.6 Blueguard DuraVision Platinum UV',
            'Zeiss 1.6 BlueProtect UV AS' ,
            'Zeiss Lotutec 1.5 Lotutec UV SPH' ,
            'Zeiss 1.6 DuraVision Platinum UV AS'];

        foreach($singleVission as $sv)
        {
            $newLensType = new Product();
            $newLensType->name = $sv;
            $newLensType->description = $sv;
            $newLensType->price = 0;
            $newLensType->save();

            if($newLensType)
            {
                $stock = new ProductStock;
                $stock->product_id = $newLensType->id;
                $stock->quantity = 0;
                $stock->save();
            }
        }



        $specialOrderLenses = [  'Zeiss Progressive Smart Life  DuraVision Platinum' ,
            'Zeiss Progressive Smart Life  DuraVision Blueprotect',
            'Zeiss Progressive Smart Life  Bluegard',
            'Zeiss Progressive Smart Life  DuraVision Platinum PhotofusionX Grey',
            'Zeiss Progressive Smart Life  DuraVision Blue Protect Photofusion Brown',
            'Zeiss Digital Smart Life  Bluegard',
            'Zeiss Digital Smart Life  DuraVision Platinum',
            'Zeiss Digital Smart Life  DuraVision Blue Protect',
            'Zeiss Digital Smart Life  DuraVision Platinum PhotofusionX Grey',
            'Zeiss Digital Smart Life  DuraVision Blue Protect Photofusion Brown'];

        foreach($specialOrderLenses as $sp)
        {
            $newLensType = new Product();
            $newLensType->name = $sp;
            $newLensType->description  = $sp;
            $newLensType->price = 0;
            $newLensType->save();

            if($newLensType)
            {
                $stock = new ProductStock;
                $stock->product_id = $newLensType->id;
                $stock->quantity = 0;
                $stock->save();
            }
        }


        $specialStockLenses = ['ZEISS FSV ClearView PFX GRY DVP 1.5','ZEISS FSV DuraVision BlueProtect 1.6 UV',
            'SYNC FSV Aspheric HMC+ 1.56','SYNC FSV Aspheric PHOTOGREY HMC+  1.56'];
        $specialStockLensesPrices = ['47150','8625','2600','8050'];

        foreach($specialStockLenses  as $index => $specialStock)
        {
            $newLensType = new Product();
            $newLensType->name        = $specialStock;
            $newLensType->description = $specialStock;
            $newLensType->price       = $specialStockLensesPrices[$index];
            $newLensType->save();

            if($newLensType)
            {
                $stock             = new ProductStock;
                $stock->product_id = $newLensType->id;
                $stock->quantity   = 0;
                $stock->save();
            }
        }

    }
}
