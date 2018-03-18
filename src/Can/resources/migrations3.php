<?php
return <<<EOF
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRolesCustomTable extends Migration {

	public function up()
	{
		Schema::create('$roleCustomTable', function(Blueprint \$table)
		{
			\$table->bigInteger('group_id')->default(0);
			\$table->string('slug', 255)->primary();
			\$table->string('name', 255)->nullable();
			\$table->string('description', 255)->nullable();
			\$table->unique(['group_id', 'slug']);
		});
	}

	public function down()
	{
		Schema::drop('$roleCustomTable');
	}
}
EOF;
