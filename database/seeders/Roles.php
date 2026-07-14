<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class Roles extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        // $role = Role::updateOrCreate(['guard_name' => 'api', 'name' => 'Супер-Админ']);
        // $permission = Permission::updateOrCreate(['guard_name' => 'api', 'name' => 'super admin']);
        // $role->syncPermissions($permission);

        // Admin
        // Permission::updateOrCreate(['guard_name' => 'api', 'name' => 'admin page access']);

        // Клиенты модулей по месячной оплате
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber']); //Все клиенты по помесячной
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber wb feedbacks']); //Доступы к модулю управления отзывами
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber wb promo calculator']); //Доступы к модулю управления отзывами
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber wb price calculator']); //Доступы к модулю ценообразование
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber wb repricer']); //Доступы к модулю репрайсера
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber wb profitability']); //Доступы к модулю рентабельности
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber wb ai cabinet analyzer']); //Доступы к модулю AiCabinet Analyzer
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber ai']); //Доступы к модулю ИИ

        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'administrator']); //доступ к всякому на фронте, чего не сделать на ларавель

        /* OZON */
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber oz feedbacks']); //Доступы к модулю управления отзывами
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'subscriber oz price calc']); //Доступы к модулю расчёта цен Ozon

        // Blog admin API
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'blog.view']);
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'blog.create']);
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'blog.update']);
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'blog.delete']);
        Permission::updateOrCreate(['guard_name' => 'web', 'name' => 'blog.publish']);


        // User::all()->each(function ($user) {
        //     $user->givePermissionTo('subscriber oz price calc');
        // });
    }
}
