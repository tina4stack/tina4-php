<?php

/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 *
 * @author Domingo Redal <docxml@gmail.com>
 */
$lang['create_private_ns']     = '¿Crear espacio de nombres privado del usuario (verificar todas las opciones antes de habilitarlo)?';
$lang['use_name_string']       = 'Usar el nombre completo del usuario en lugar del nombre de usuario para su espacio de nombres privado.';
$lang['use_start_page']        = 'Usar el nombre de la página de inicio del wiki para la página de inicio de cada espacio de nombres privado (de lo contrario, se usará el nombre del espacio de nombres privado).';
$lang['users_namespace']       = 'Espacio de nombre bajo el cual se crean los espacios de nombres de los usuarios.';
$lang['group_by_name']         = '¿Agrupar los espacios de nombres de los usuarios por el primer carácter del nombre de usuario?';
$lang['edit_before_create']    = 'Permitir que los usuarios editen la página de inicio de su espacio de nombres privado en la creación (solo funcionará si una página pública no se genera al mismo tiempo).';
$lang['acl_all_private']       = 'Permisos para el grupo @ALL en espacios de nombres privados';
$lang['acl_all_private_o_0']   = 'Ninguno (predeterminado)';
$lang['acl_all_private_o_1']   = 'Leer';
$lang['acl_all_private_o_2']   = 'Editar';
$lang['acl_all_private_o_4']   = 'Crear';
$lang['acl_all_private_o_8']   = 'Subir';
$lang['acl_all_private_o_16']  = 'Borrar';
$lang['acl_all_private_o_noacl'] = 'No ACL automático';
$lang['acl_user_private']      = 'Permisos para el grupo @user en espacios de nombres privados';
$lang['acl_user_private_o_0']  = 'Ninguno (predeterminado)';
$lang['acl_user_private_o_1']  = 'Leer';
$lang['acl_user_private_o_2']  = 'Editar';
$lang['acl_user_private_o_4']  = 'Crear';
$lang['acl_user_private_o_8']  = 'Subir';
$lang['acl_user_private_o_16'] = 'Borrar';
$lang['acl_user_private_o_noacl'] = 'No ACL automático';
$lang['groups_private']        = 'Lista separada por comas de grupos de usuarios afectados por la creación de espacios de nombres privados (déjelo vacío para aplicar la configuración anterior a todos los usuarios).';
$lang['create_public_page']    = '¿Crear una página pública de usuario?';
$lang['public_pages_ns']       = 'Espacio de nombre bajo el cual se crean las páginas públicas.';
$lang['acl_all_public']        = 'Permisos para el grupo @ALL en páginas públicas';
$lang['acl_all_public_o_0']    = 'Ninguno';
$lang['acl_all_public_o_1']    = 'Leer (predeterminado)';
$lang['acl_all_public_o_2']    = 'Editar';
$lang['acl_all_public_o_noacl'] = 'No ACL automático';
$lang['acl_user_public']       = 'Permisos para el grupo @user en páginas públicas';
$lang['acl_user_public_o_0']   = 'Ninguno';
$lang['acl_user_public_o_1']   = 'Leer (predeterminado)';
$lang['acl_user_public_o_2']   = 'Editar';
$lang['acl_user_public_o_noacl'] = 'No ACL automático';
$lang['groups_public']         = 'Lista separada por comas de los grupos de usuarios afectados por la creación de página pública (déjelo vacío para aplicar la configuración anterior a todos los usuarios).';
$lang['templates_path']        = 'Ruta relativa desde [<code>savedir</code>] donde las plantillas serán almacenadas (userhomepage_private.txt y userhomepage_public.txt). Ejemplos: <code>./pages/user</code> o <code>../lib/plugins/userhomepage</code>.';
$lang['templatepath']          = 'Ruta de la plantilla desde la versión 3.0.4. Si este fichero existe, se utilizará como fuente predeterminada para la nueva plantilla de página de inicio del espacio de nombres privado (borre la ruta si no lo desea).';
$lang['acl_all_templates']     = 'Permisos para el grupo @ALL en plantillas (si están almacenadas en <code>data/pages...</code>)';
$lang['acl_all_templates_o_0'] = 'Ninguno';
$lang['acl_all_templates_o_1'] = 'Leer (predeterminado)';
$lang['acl_all_templates_o_2'] = 'Editar';
$lang['acl_all_templates_o_noacl'] = 'No ACL automático';
$lang['acl_user_templates']    = 'Permisos para el grupo @user en plantillas (si están almacenadas en <code>data/pages...</code>)';
$lang['acl_user_templates_o_0'] = 'Ninguno';
$lang['acl_user_templates_o_1'] = 'Leer (predeterminado)';
$lang['acl_user_templates_o_2'] = 'Editar';
$lang['acl_user_templates_o_noacl'] = 'No ACL automático';
$lang['no_acl']                = 'No hay configuración automática de ACL, pero tendrá que eliminar las creadas hasta ahora manualmente. No te olvides de configurar algunas ACL en las plantillas.';
$lang['redirection']           = 'Habilite la redirección (incluso si está deshabilitada, seguirá ocurriendo en la creación de páginas).';
$lang['action']                = 'Acción en la primera redirección a página pública después de su creación (o página de inicio de espacio de nombre privado).';
$lang['action_o_edit']         = 'Editar (predeterminado)';
$lang['action_o_show']         = 'Mostrar';
$lang['userlink_replace']      = 'Habilite la sustitución del enlace interwiki [<code>Conectado como</code>], de las páginas creadas por Userhomepage (solo funciona si la opción <code>showuseras</code> está configurada como enlace interwiki).';
$lang['userlink_classes']      = 'Lista separada por espacios de clases CSS para aplicar al enlace interwiki [<code>Conectado como</code>] (predeterminado: <code>interwiki iw_user wikilink1</code>).';
$lang['userlink_fa']           = '¿Usar iconos Fontawesome en lugar de imágenes (Fontawesome tiene que ser instalado por la plantilla o por un complemento)?';
