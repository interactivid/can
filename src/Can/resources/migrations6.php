<?php
return <<<EOF
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangePrimaryKeyInRolesCustomTable extends Migration {

	public function up()
	{
		Schema::table('$roleCustomTable', function(Blueprint \$table)
		{
			\$table->dropPrimary();
			\$table->dropUnique('roles_custom_group_id_slug_unique');
			\$table->primary(['group_id', 'slug']);
		});
	}

	public function down()
	{
		Schema::table('$roleCustomTable', function(Blueprint \$table)
		{			
			\$table->dropPrimary();
			\$table->unique(['group_id', 'slug']);
			\$table->primary(['slug']);
		});
	}
}
EOF;
