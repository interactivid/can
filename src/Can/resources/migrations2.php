<?php
return <<<EOF
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGroupIdsToCanTables extends Migration {

	public function up()
	{
		Schema::table('$userRoleTable', function(Blueprint \$table)
		{
			\$table->bigInteger('group_id')->default(0);
			\$table->unique(['user_id', 'group_id', 'roles_slug']);
		});

		Schema::table('$userPermissionTable', function(Blueprint \$table)
		{
			\$table->bigInteger('group_id')->default(0);
			\$table->unique(['user_id', 'group_id', 'permissions_slug']);
		});
	}

	public function down()
	{
		Schema::table('$userRoleTable', function(Blueprint \$table)
		{
			\$table->dropUnique('pivot_users_roles_user_id_group_id_roles_slug_unique');
			\$table->dropColumn('group_id');
		});

		Schema::table('$userPermissionTable', function(Blueprint \$table)
		{
			\$table->dropUnique('pivot_users_permissions_user_id_group_id_permissions_slug_uniqu');
			\$table->dropColumn('group_id');
		});
	}
}
EOF;
