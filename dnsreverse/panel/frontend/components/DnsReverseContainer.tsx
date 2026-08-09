import React, { useEffect, useState } from 'react';
import http from '@/api/http';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { ServerContext } from '@/state/server';

/**
 * DNS Reverse - pantalla del area de cliente (modo nativo).
 *
 * Este componente se compila DENTRO del panel con "yarn build", igual que
 * cualquier pantalla de Pterodactyl. Es la alternativa al modo inyectado: no
 * anade ninguna etiqueta a las paginas del panel, a cambio de tener que
 * recompilar el frontend al instalar y despues de cada actualizacion del panel.
 *
 * Los estilos se cargan del archivo que ya instala la extension en
 * public/extensions/dnsreverse/client.css, asi que no depende de como este
 * configurado webpack ni del tema.
 */

interface Registro {
    id: number;
    domain: string;
    url: string;
    type: string;
    type_label: string;
    ssl: boolean;
    ssl_mode: string;
    ssl_label: string;
    address: string | null;
    created_at: string | null;
    error: string | null;
}

interface DominioBase {
    id: number;
    domain: string;
    allow_subdomain: boolean;
    allow_srv: boolean;
    has_origin_cert: boolean;
    allow_letsencrypt: boolean;
    automatic_dns: boolean;
}

interface Asignacion {
    id: number;
    label: string;
    port: number;
    default: boolean;
}

interface Configuracion {
    types: string[];
    limit: number;
    used: number;
    remaining: number;
    domains: DominioBase[];
    allocations: Asignacion[];
    allow_custom_domains: boolean;
    letsencrypt: boolean;
    can_manage: boolean;
    dns_instructions: string;
}

type Aviso = { tipo: 'ok' | 'error' | 'info'; texto: string } | null;

/**
 * Carga la hoja de estilos una sola vez, en el <head>.
 *
 * Es el mismo archivo que usa el modo inyectado, asi que las dos pantallas se
 * ven identicas y solo hay un sitio donde tocar los estilos.
 */
const cargarEstilos = () => {
    const id = 'dnsreverse-css';

    if (document.getElementById(id)) {
        return;
    }

    const etiqueta = document.createElement('link');
    etiqueta.id = id;
    etiqueta.rel = 'stylesheet';
    etiqueta.href = '/extensions/dnsreverse/client.css';
    document.head.appendChild(etiqueta);
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    const [cargando, setCargando] = useState(true);
    const [registros, setRegistros] = useState<Registro[]>([]);
    const [config, setConfig] = useState<Configuracion | null>(null);
    const [aviso, setAviso] = useState<Aviso>(null);
    const [enviando, setEnviando] = useState(false);

    // Formulario
    const [tipo, setTipo] = useState('subdomain');
    const [nombre, setNombre] = useState('');
    const [dominioId, setDominioId] = useState<number>(0);
    const [asignacion, setAsignacion] = useState<number>(0);
    const [modoSsl, setModoSsl] = useState('origin');
    const [cert, setCert] = useState('');
    const [clave, setClave] = useState('');

    const cargar = () => {
        http.get(`/api/dnsreverse/server/${uuid}`)
            .then(({ data }) => {
                setRegistros(data.records || []);
                setConfig(data.config);

                const tipos: string[] = data.config?.types || [];
                const dominios: DominioBase[] = data.config?.domains || [];

                if (tipos.indexOf('subdomain') === -1 || dominios.length === 0) {
                    setTipo(tipos.indexOf('domain') !== -1 ? 'domain' : tipos[0] || 'domain');
                }

                if (dominios.length > 0) {
                    setDominioId((actual) => (actual > 0 ? actual : dominios[0].id));
                }

                const asignaciones: Asignacion[] = data.config?.allocations || [];
                const principal = asignaciones.find((a) => a.default) || asignaciones[0];

                if (principal) {
                    setAsignacion((actual) => (actual > 0 ? actual : principal.id));
                }
            })
            .catch(() => setAviso({ tipo: 'error', texto: 'No se pudo cargar la informacion. Recarga la pagina.' }))
            .then(() => setCargando(false));
    };

    useEffect(cargarEstilos, []);
    useEffect(cargar, [uuid]);

    const dominioElegido = (config?.domains || []).find((d) => d.id === dominioId) || null;

    // Opciones de certificado disponibles segun lo elegido.
    const opcionesSsl = (): { valor: string; titulo: string; texto: string }[] => {
        const opciones: { valor: string; titulo: string; texto: string }[] = [];

        if (tipo === 'subdomain' && dominioElegido && dominioElegido.has_origin_cert) {
            opciones.push({
                valor: 'origin',
                titulo: 'Certificado de origen (recomendado)',
                texto: 'Ya lo tenemos puesto nosotros. Tu no tienes que hacer nada y no caduca en anos.',
            });
        }

        if (tipo === 'domain') {
            opciones.push({
                valor: 'origin',
                titulo: 'Certificado de origen de Cloudflare',
                texto: 'Lo generas tu en Cloudflare (SSL/TLS → Origin Server) y lo pegas aqui. Necesita la nube naranja puesta.',
            });
        }

        if (config?.letsencrypt && (!dominioElegido || dominioElegido.allow_letsencrypt) && tipo !== 'srv') {
            opciones.push({
                valor: 'letsencrypt',
                titulo: "Certificado automatico (Let's Encrypt)",
                texto: 'Se pide solo y se renueva solo. El dominio tiene que apuntar directo a nuestra IP, con la nube gris ("DNS only") en Cloudflare.',
            });
        }

        opciones.push({
            valor: 'none',
            titulo: 'Sin candado (solo http)',
            texto: 'La pagina cargara sin HTTPS y el navegador la marcara como no segura. Solo para pruebas.',
        });

        return opciones;
    };

    // Si la opcion elegida deja de estar disponible, se pasa a la primera.
    useEffect(() => {
        const disponibles = opcionesSsl().map((o) => o.valor);

        if (disponibles.indexOf(modoSsl) === -1 && disponibles.length > 0) {
            setModoSsl(disponibles[0]);
        }
    }, [tipo, dominioId, config]);

    const necesitaPegarCertificado = modoSsl === 'origin' && !(tipo === 'subdomain' && dominioElegido?.has_origin_cert);

    const crear = (evento: React.FormEvent) => {
        evento.preventDefault();

        if (enviando) {
            return;
        }

        if (!nombre.trim()) {
            setAviso({ tipo: 'error', texto: 'Escribe el nombre del dominio.' });

            return;
        }

        setEnviando(true);
        setAviso({
            tipo: 'info',
            texto: 'Creando el dominio y pidiendo el certificado. Esto puede tardar hasta un par de minutos, no cierres la pagina.',
        });

        http.post(
            `/api/dnsreverse/server/${uuid}`,
            {
                type: tipo,
                name: nombre.trim().toLowerCase(),
                domain_id: dominioId,
                allocation_id: asignacion,
                ssl_mode: tipo === 'srv' ? 'none' : modoSsl,
                ssl_cert: cert,
                ssl_key: clave,
            },
            // El certificado puede tardar: el tiempo de espera de serie del
            // panel (20 segundos) se queda muy corto.
            { timeout: 180000 }
        )
            .then(({ data }) => {
                if (data.warning) {
                    setAviso({ tipo: 'error', texto: `${data.record.domain}: ${data.warning}` });
                } else {
                    setAviso({
                        tipo: 'ok',
                        texto: `${data.record.domain} ya esta configurado. Si acabas de crear el registro DNS, puede tardar unos minutos en verse desde todos los sitios.`,
                    });
                }

                setNombre('');
                setCert('');
                setClave('');
                cargar();
            })
            .catch((error) => {
                const mensaje =
                    error?.response?.data?.error ||
                    error?.response?.data?.errors?.[0]?.detail ||
                    'No se pudo crear el DNS. Intentalo de nuevo en unos minutos.';

                setAviso({ tipo: 'error', texto: mensaje });
            })
            .then(() => setEnviando(false));
    };

    const borrar = (registro: Registro) => {
        if (!window.confirm(`¿Seguro que quieres borrar ${registro.domain}?\n\nSe quitara el registro DNS y la configuracion del servidor. Tu servidor y tus archivos no se tocan.`)) {
            return;
        }

        http.delete(`/api/dnsreverse/server/${uuid}/${registro.id}`, { timeout: 60000 })
            .then(() => {
                setAviso({ tipo: 'ok', texto: `${registro.domain} se ha borrado.` });
                cargar();
            })
            .catch(() => setAviso({ tipo: 'error', texto: 'No se pudo borrar. Intentalo de nuevo.' }));
    };

    if (cargando) {
        return (
            <ServerContentBlock title={'DNS Reverse'}>
                <Spinner size={'large'} centered />
            </ServerContentBlock>
        );
    }

    if (!config || config.types.length === 0) {
        return (
            <ServerContentBlock title={'DNS Reverse'}>
                <div className={'dnsrev-nativo'}>
                    <div className={'dnsrev-alert dnsrev-alert-info'}>
                        Este tipo de servidor no admite DNS. Si crees que es un error, escribe al soporte.
                    </div>
                </div>
            </ServerContentBlock>
        );
    }

    const dominiosParaTipo = (config.domains || []).filter((d) => (tipo === 'srv' ? d.allow_srv : d.allow_subdomain));

    return (
        <ServerContentBlock title={'DNS Reverse'}>
            <div className={'dnsrev-nativo'}>
                {aviso && (
                    <div className={`dnsrev-form-result dnsrev-result-${aviso.tipo}`} style={{ marginBottom: 16 }}>
                        <span>{aviso.texto}</span>
                    </div>
                )}

                <details className={'dnsrev-help'} open={registros.length === 0}>
                    <summary>
                        <span>¿Como funciona esto? (leelo la primera vez)</span>
                    </summary>
                    <div className={'dnsrev-help-body'}>
                        <p>
                            Aqui pones un <strong>dominio bonito</strong> a tu servidor, para que la gente entre por{' '}
                            <code>tunombre.com</code> en vez de por una IP con puerto.
                        </p>
                        <h4>Tienes dos formas de conseguir el dominio</h4>
                        <ul>
                            {config.domains.length > 0 && (
                                <li>
                                    <strong>Subdominio nuestro:</strong> eliges un nombre y te lo damos hecho. No tienes
                                    que comprar nada ni configurar nada.
                                </li>
                            )}
                            {config.allow_custom_domains && (
                                <li>
                                    <strong>Tu propio dominio:</strong> si ya compraste uno, lo apuntas a nuestra IP y lo
                                    pones aqui.
                                </li>
                            )}
                        </ul>
                        <h4>Y dos formas de tener el candado (HTTPS)</h4>
                        <ul>
                            <li>
                                <strong>Certificado automatico (Let&apos;s Encrypt):</strong> se pide solo y no tienes que
                                hacer nada. Dura 90 dias y se renueva solo antes de caducar. <em>Requisito:</em> el
                                dominio tiene que apuntar directamente a nuestra IP, con la <strong>nube gris</strong> en
                                Cloudflare (opcion &quot;DNS only&quot;).
                            </li>
                            <li>
                                <strong>Certificado de origen:</strong> es el que genera Cloudflare desde{' '}
                                <em>SSL/TLS &rarr; Origin Server</em>. Dura 15 anos, pero{' '}
                                <strong>solo vale si el trafico pasa por Cloudflare</strong>, o sea con la{' '}
                                <strong>nube naranja</strong> puesta.
                            </li>
                        </ul>
                        <p className={'dnsrev-help-tip'}>
                            <span>
                                Resumen facil: <strong>nube gris = Let&apos;s Encrypt</strong>,{' '}
                                <strong>nube naranja = certificado de origen</strong>. Mezclarlos es lo que provoca el
                                famoso error 526 de Cloudflare.
                            </span>
                        </p>
                    </div>
                </details>

                <div className={'dnsrev-section'}>
                    <div className={'dnsrev-section-head'}>
                        <h3>Tus DNS</h3>
                        <span className={'dnsrev-counter'}>
                            {registros.length} de {config.limit}
                        </span>
                    </div>

                    {registros.length === 0 ? (
                        <div className={'dnsrev-empty'}>
                            <p>Todavia no tienes ningun dominio. Crea el primero abajo.</p>
                        </div>
                    ) : (
                        <div className={'dnsrev-list'}>
                            {registros.map((registro) => (
                                <div className={'dnsrev-card'} key={registro.id}>
                                    <div className={'dnsrev-card-main'}>
                                        <div className={'dnsrev-card-domain'}>
                                            {registro.type === 'srv' ? (
                                                <strong>{registro.domain}</strong>
                                            ) : (
                                                <a href={registro.url} target={'_blank'} rel={'noopener noreferrer'}>
                                                    <strong>{registro.domain}</strong>
                                                </a>
                                            )}
                                        </div>
                                        <div className={'dnsrev-card-meta'}>
                                            <span>{registro.address || '-'}</span>
                                            <span>{registro.ssl_label}</span>
                                            <span className={'dnsrev-tag'}>{registro.type_label}</span>
                                        </div>
                                        {registro.error && <div className={'dnsrev-card-error'}>{registro.error}</div>}
                                    </div>
                                    {config.can_manage && (
                                        <button
                                            type={'button'}
                                            className={'dnsrev-btn dnsrev-btn-danger'}
                                            onClick={() => borrar(registro)}
                                        >
                                            <span>Borrar</span>
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {config.can_manage && config.remaining < 1 && (
                    <div className={'dnsrev-alert dnsrev-alert-info'}>
                        <span>
                            Has usado los <strong>{config.limit}</strong> DNS de este servidor. Borra uno para crear otro,
                            o pide al soporte que te suba el limite.
                        </span>
                    </div>
                )}

                {config.can_manage && config.remaining > 0 && (
                    <div className={'dnsrev-section'}>
                        <div className={'dnsrev-section-head'}>
                            <h3>Crear un DNS nuevo</h3>
                        </div>

                        <form className={'dnsrev-form'} onSubmit={crear}>
                            <div className={'dnsrev-field'}>
                                <label>Que quieres hacer</label>
                                <select className={'dnsrev-input'} value={tipo} onChange={(e) => setTipo(e.target.value)}>
                                    {config.types.indexOf('subdomain') !== -1 && config.domains.some((d) => d.allow_subdomain) && (
                                        <option value={'subdomain'}>Subdominio nuestro (lo mas facil)</option>
                                    )}
                                    {config.types.indexOf('domain') !== -1 && config.allow_custom_domains && (
                                        <option value={'domain'}>Mi propio dominio</option>
                                    )}
                                    {config.types.indexOf('srv') !== -1 && config.domains.some((d) => d.allow_srv) && (
                                        <option value={'srv'}>Minecraft SRV (entrar sin escribir el puerto)</option>
                                    )}
                                </select>
                            </div>

                            {tipo === 'domain' ? (
                                <div className={'dnsrev-field'}>
                                    <label>Tu dominio</label>
                                    <input
                                        className={'dnsrev-input'}
                                        value={nombre}
                                        onChange={(e) => setNombre(e.target.value)}
                                        placeholder={'mipagina.com'}
                                        spellCheck={false}
                                    />
                                    <p className={'dnsrev-help-text'}>
                                        Escribelo en minusculas, sin <code>http://</code> y sin barras.
                                    </p>
                                    <div className={'dnsrev-note'}>
                                        <div>
                                            <strong>Antes de darle a crear</strong>
                                            <p>{config.dns_instructions}</p>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className={'dnsrev-row'}>
                                    <div className={'dnsrev-field'}>
                                        <label>Nombre que quieres</label>
                                        <input
                                            className={'dnsrev-input'}
                                            value={nombre}
                                            onChange={(e) => setNombre(e.target.value)}
                                            placeholder={'mipagina'}
                                            spellCheck={false}
                                        />
                                        <p className={'dnsrev-help-text'}>
                                            Solo letras, numeros y guiones. Se vera como{' '}
                                            <code>
                                                {(nombre || 'minombre').toLowerCase().replace(/[^a-z0-9-]/g, '')}.
                                                {dominioElegido?.domain || ''}
                                            </code>
                                        </p>
                                    </div>
                                    <div className={'dnsrev-field'}>
                                        <label>Dominio</label>
                                        <select
                                            className={'dnsrev-input'}
                                            value={dominioId}
                                            onChange={(e) => setDominioId(Number(e.target.value))}
                                        >
                                            {dominiosParaTipo.map((d) => (
                                                <option key={d.id} value={d.id}>
                                                    .{d.domain}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                            )}

                            {tipo !== 'domain' && dominioElegido && !dominioElegido.automatic_dns && (
                                <div className={'dnsrev-note'}>
                                    <div>
                                        <strong>Este dominio se configura a mano</strong>
                                        <p>
                                            Se preparara tu servidor al momento, pero el registro DNS lo tiene que crear
                                            un administrador. Puede tardar un rato en funcionar.
                                        </p>
                                    </div>
                                </div>
                            )}

                            <div className={'dnsrev-field'}>
                                <label>Puerto de tu servidor</label>
                                <select
                                    className={'dnsrev-input'}
                                    value={asignacion}
                                    onChange={(e) => setAsignacion(Number(e.target.value))}
                                >
                                    {config.allocations.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.label}
                                            {a.default ? ' (principal)' : ''}
                                        </option>
                                    ))}
                                </select>
                                <p className={'dnsrev-help-text'}>
                                    Es el puerto al que se enviaran las visitas de ese dominio.
                                </p>
                            </div>

                            {tipo !== 'srv' && (
                                <div className={'dnsrev-field'}>
                                    <label>Candado (HTTPS)</label>
                                    <div className={'dnsrev-choices'}>
                                        {opcionesSsl().map((opcion) => (
                                            <label className={'dnsrev-choice'} key={opcion.valor}>
                                                <input
                                                    type={'radio'}
                                                    name={'ssl_mode'}
                                                    value={opcion.valor}
                                                    checked={modoSsl === opcion.valor}
                                                    onChange={() => setModoSsl(opcion.valor)}
                                                />
                                                <span className={'dnsrev-choice-body'}>
                                                    <strong>{opcion.titulo}</strong>
                                                    <span>{opcion.texto}</span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {tipo !== 'srv' && necesitaPegarCertificado && (
                                <>
                                    <div className={'dnsrev-field'}>
                                        <label>Certificado</label>
                                        <textarea
                                            className={'dnsrev-input dnsrev-mono'}
                                            rows={5}
                                            value={cert}
                                            onChange={(e) => setCert(e.target.value)}
                                            placeholder={'-----BEGIN CERTIFICATE-----'}
                                        />
                                    </div>
                                    <div className={'dnsrev-field'}>
                                        <label>Clave privada</label>
                                        <textarea
                                            className={'dnsrev-input dnsrev-mono'}
                                            rows={5}
                                            value={clave}
                                            onChange={(e) => setClave(e.target.value)}
                                            placeholder={'-----BEGIN PRIVATE KEY-----'}
                                        />
                                        <p className={'dnsrev-help-text'}>
                                            Se manda a tu servidor y se guarda ahi. Nadie mas la ve.
                                        </p>
                                    </div>
                                </>
                            )}

                            <div className={'dnsrev-form-actions'}>
                                <button type={'submit'} className={'dnsrev-btn dnsrev-btn-primary'} disabled={enviando}>
                                    <span>{enviando ? 'Creando...' : 'Crear DNS'}</span>
                                </button>
                                <span className={'dnsrev-hint'}>
                                    Te quedan {config.remaining} de {config.limit}.
                                </span>
                            </div>
                        </form>
                    </div>
                )}
            </div>
        </ServerContentBlock>
    );
};
