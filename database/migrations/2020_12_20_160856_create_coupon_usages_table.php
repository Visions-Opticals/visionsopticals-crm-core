<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCouponUsagesTable extends Migration
{
    public function up()
    {
        Schema::create('coupon_usages', function (Blueprint $table) {

		$table->integer('id')->primary()->unsigned();
		$table->char('uuid',50);
		$table->integer('coupon_id')->unsigned();
		$table->integer('user_id')->unsigned();
		$table->timestamps();
        $table->foreign('coupon_id')->references('id')->on('coupons');
        $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down()
    {
        Schema::dropIfExists('coupon_usages');
    }
}