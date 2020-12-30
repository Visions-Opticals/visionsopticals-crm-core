<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaxAuthoritiesTable extends Migration
{
    public function up()
    {
        Schema::create('tax_authorities', function (Blueprint $table) {

		$table->increments(id)->unsigned();
		$table->char('uuid',36);
		$table->integer('company_id',)->unsigned();
		$table->string('authority_name');
		$table->enum('payment_mode',['Paystack','Flutterwave','Hubspot']);
		$table->tinyInteger('isActive',1)->default('1');
		;
		$table->timestamp('created_at')->nullable()->default('NULL');
		$table->timestamp('updated_at')->nullable()->default('NULL');
		$table->string('default_payment_details',45);
		$table->primary('id');
		$table->foreign('company_id')->references('id')->on('companies');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tax_authorities');
    }
}