<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Toda la API se autentica con token Bearer de Sanctum. Declararlo aquí
        // hace que la documentación muestre el botón de autorizar y permita
        // probar los endpoints desde el navegador.
        Scramble::configure()->withDocumentTransformers(
            fn(OpenApi $openApi) => $openApi->secure(SecurityScheme::http('bearer'))
        );
    }
}
