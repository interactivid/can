<?php
return <<<EOF
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangePrimaryKeyInCanTables extends Migration {

	public function up()
	{
		Schema::table('$userRoleTable', function(Blueprint \$table)
		{
			\$table->dropPrimary();
			\$table->dropUnique('pivot_users_roles_user_id_group_id_roles_slug_unique');
			\$table->primary(['user_id', 'group_id', 'roles_slug']);
		});

		Schema::table('$userPermissionTable', function(Blueprint \$table)
		{
			\$table->dropPrimary();
			\$table->dropUnique('pivot_users_permissions_user_id_group_id_permissions_slug_uniqu');
			\$table->primary(['user_id', 'group_id', 'permissions_slug']);
		});
	}

	public function down()
	{
		Schema::table('$userRoleTable', function(Blueprint \$table)
		{
			\$table->dropPrimary();
			\$table->unique(['user_id', 'group_id', 'roles_slug']);
			\$table->primary(['user_id', 'roles_slug']);
		});

		Schema::table('$userPermissionTable', function(Blueprint \$table)
		{
			\$table->dropPrimary();
			\$table->unique(['user_id', 'group_id', 'permissions_slug']);
			\$table->primary(['user_id', 'permissions_slug']);
		});
	}
}
EOF;
