<?php

/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * 
 * @author   Simon DELAGE <sdelage@gmail.com>
 * @author Padhie <develop@padhie.de>
 * @author Dana <dannax3@gmx.de>
 */
$lang['create_private_ns']     = 'Privaten Namespace für die Benutzer anlegen (alle anderen Optionen überprüfen, bevor diese Option aktiviert wird!)';
$lang['use_name_string']       = 'Den vollen Namen des Benutzers statt des Logins für seinen privaten Namespace benutzen';
$lang['use_start_page']        = 'Den Startseitennamen des Wikis für die Startseiten der privaten Namespaces benutzen (andernfalls wird der Name des privaten Namespace benutzt).';
$lang['users_namespace']       = 'Namespace, unter dem die privaten Namespaces der Benutzer angelegt werden';
$lang['group_by_name']         = 'Namespaces der Benutzer nach dem ersten Buchstaben des Benutzernamens gruppieren';
$lang['edit_before_create']    = 'Benutzern erlauben, die Startseite ihres privaten Namespace beim Anlegen zu bearbeiten (funktioniert nur, wenn nicht gleichzeitig eine öffentliche Seite angelegt wird)';
$lang['acl_all_private']       = 'Berechtigungen der Gruppe @ALL für private Namespaces';
$lang['acl_all_private_o_0']   = 'Keine (Standard)';
$lang['acl_all_private_o_1']   = 'Lesen';
$lang['acl_all_private_o_2']   = 'Bearbeiten';
$lang['acl_all_private_o_4']   = 'Anlegen';
$lang['acl_all_private_o_8']   = 'Hochladen';
$lang['acl_all_private_o_16']  = 'Entfernen';
$lang['acl_all_private_o_noacl'] = 'Kein automatischer ACL-Eintrag';
$lang['acl_user_private']      = 'Berechtigungen der Gruppe @user für private Namespaces';
$lang['acl_user_private_o_0']  = 'Keine (Standard)';
$lang['acl_user_private_o_1']  = 'Lesen';
$lang['acl_user_private_o_2']  = 'Bearbeiten';
$lang['acl_user_private_o_4']  = 'Anlegen';
$lang['acl_user_private_o_8']  = 'Hochladen';
$lang['acl_user_private_o_16'] = 'Entfernen';
$lang['acl_user_private_o_noacl'] = 'Kein automatischer ACL-Eintrag';
$lang['groups_private']        = 'Durch Kommata getrennte Liste von Benutzergruppen bezüglich der Erstellung von privaten Namesräumen (Freilassen, um oben stehende Einstellungen auf alle Benutzer anzuwenden).';
$lang['create_public_page']    = 'Öffentliche Seite für die Benutzer anlegen';
$lang['public_pages_ns']       = 'Namespace, unter dem die öffentlichen Seiten der Benutzer angelegt werden';
$lang['acl_all_public']        = 'Berechtigungen der Gruppe @ALL für öffentliche Seiten';
$lang['acl_all_public_o_0']    = 'Keine';
$lang['acl_all_public_o_1']    = 'Lesen (Standard)';
$lang['acl_all_public_o_2']    = 'Bearbeiten';
$lang['acl_all_public_o_noacl'] = 'Kein automatischer ACL-Eintrag';
$lang['acl_user_public']       = 'Berechtigungen für @user Gruppe auf öffentlichen Seiten';
$lang['acl_user_public_o_0']   = 'Keine';
$lang['acl_user_public_o_1']   = 'Lesen (Standard)';
$lang['acl_user_public_o_2']   = 'Bearbeiten';
$lang['acl_user_public_o_noacl'] = 'Kein automatischer ACL-Eintrag';
$lang['groups_public']         = 'Durch Kommata getrennte Liste von Benutzergruppen bezüglich der Erstellung von öffentlichen Seiten (Freilassen, um oben stehende Einstellungen auf alle Benutzer anzuwenden).';
$lang['templates_path']        = 'Relativer Pfad von [<code>savedir</code>] wo Templates gespeichert werden (userhomepage_private.txt and userhomepage_public.txt). Beispiel: <code>./pages/user</code> or <code>../lib/plugins/userhomepage</code>.';
$lang['templatepath']          = 'Templatepfad aus Version 3.0.4. Exisitert diese Datei, wird sie als Template für die Startseite neuer privater Namespaces verwendet (löschen, wenn dies nicht gewünscht wird)';
$lang['acl_all_templates']     = 'Berechtigungen der Gruppe @ALL für Templates, die in <code>data/pages...</code> liegen';
$lang['acl_all_templates_o_0'] = 'Keine';
$lang['acl_all_templates_o_1'] = 'Lesen (Standard)';
$lang['acl_all_templates_o_2'] = 'Bearbeiten';
$lang['acl_all_templates_o_noacl'] = 'Kein automatischer ACL-Eintrag';
$lang['acl_user_templates']    = 'Berechtigungen der Gruppe @user für Templates, die in <code>data/pages...</code> liegen';
$lang['acl_user_templates_o_0'] = 'Keine';
$lang['acl_user_templates_o_1'] = 'Lesen (Standard)';
$lang['acl_user_templates_o_2'] = 'Bearbeiten';
$lang['acl_user_templates_o_noacl'] = 'Kein automatischer ACL-Eintrag';
$lang['no_acl']                = 'Automatische Erstellung von ACL-Einträgen deaktivieren; bereits erstellte Einträge müssen manuell entfernt werden. Vergessen Sie nicht, ggf. ACL-Einträge für die Templates zu erstellen, falls diese in <code>data/pages...</code> liegen.';
$lang['redirection']           = 'Aktivieren einer Weiterleitung (Auch bei Deaktivierung, wird dies weiterhin bei der Erstellung von Seiten auftreten).';
$lang['action']                = 'Aktion bei der ersten Weiterleitung auf eine öffentliche Seite nach deren Erstellung (oder auf Startseite eines privaten Namespace).';
$lang['action_o_edit']         = 'Bearbeiten';
$lang['action_o_show']         = 'Anzeigen';
$lang['userlink_replace']      = 'Aktivieren des Ersetzens des [<code>Logged in as</code>] interwiki-Links, abhängig von Seiten erstellt durch Userhomepage (Funktioniert nur, wenn <code>showuseras</code> Option auf interwiki-Link gesetzt ist).';
$lang['userlink_classes']      = 'Durch Leerzeichen getrennte Liste von CSS-Klassen die auf  [<code>Logged in as</code>] Interwiki-Links angewendet werden (Default: <code>interwiki iw_user wikilink1</code>).';
$lang['userlink_fa']           = 'Benutzen von Fontawesome-Icons anstelle von Bildern (Fontawesome muss durch ein Template oder Plugin installiert sein) ?';
