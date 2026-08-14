# Pruebas de DNS Reverse

`prueba-dns.php` comprueba, sin tocar Cloudflare de verdad, que la extension
no vuelve a caer en los fallos que hacian que el dominio de un cliente no
cargara nunca (`DNS_PROBE_FINISHED_NXDOMAIN`, el cartel de "revisa que no haya
errores de ortografia").

Cubre nueve bloques:

1. La zona de Cloudflare no esta activa → se para y se explica que nameservers
   hay que poner.
2. Zona activa → se crea el registro y se vuelve a leer para confirmarlo.
3. El registro ya existia apuntando a otro sitio → se corrige en vez de fallar.
4. El identificador de zona guardado es de otra zona → se busca de nuevo y no
   se crea nada en la zona equivocada.
5. El certificado del cliente se guarda cifrado y se recupera entero.
6. Crear un DNS con la zona sin activar → no se guarda nada y se avisa.
7. Crear un DNS con todo bien → queda con certificado y con la caducidad de los
   90 dias apuntada.
8. El cliente trae su dominio y su certificado de origen → se valida, se manda
   al nodo y la resincronizacion lo vuelve a mandar si el nodo se reinstala.
9. Un certificado que no es de ese dominio → se rechaza.

## Como se ejecuta

Hace falta una aplicacion Laravel con:

- las clases de `Pterodactyl\Models` (`Server`, `User`, `Node`, `Egg`,
  `Allocation`) disponibles, aunque sean simples,
- la extension copiada en `app/Extensions/DnsReverse`,
- sus migraciones aplicadas,
- `openssl` en el sistema (para el certificado de mentira del bloque 8).

```bash
php artisan migrate --path=app/Extensions/DnsReverse/database/migrations
php prueba-dns.php
```

Termina con `TODO BIEN` y codigo de salida 0, o con la cuenta de fallos y
codigo 1.

No hace ni una sola llamada de red: todo Cloudflare, los resolutores publicos
de DNS y wings van con `Http::fake()`.
