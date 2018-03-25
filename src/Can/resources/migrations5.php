<?php
return <<<EOF
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGroupIdToRolePermissionTable extends Migration {

	public function up()
	{
		Schema::table('$rolePermissionTable', function(Blueprint \$table)
		{
			\$table->dropPrimary();
			\$table->bigInteger('group_id')->default(0);
			\$table->primary(['group_id', 'roles_slug', 'permissions_slug']);
		});
	}

	public function down()
	{
		Schema::table('$rolePermissionTable', function(Blueprint \$table)
		{			
			\$table->dropPrimary();
			\$table->dropColumn('group_id');
			\$table->primary(['roles_slug', 'permissions_slug']);
		});
	}
}
EOF;
