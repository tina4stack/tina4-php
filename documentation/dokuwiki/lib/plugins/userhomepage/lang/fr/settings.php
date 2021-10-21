<?php
/**
 * French settings file for Userhomepage plugin
 * Previous authors: James GuanFeng Lin, Mikhail I. Izmestev, Daniel Stonier
 * @author: Simon DELAGE <sdelage@gmail.com>
 * @license: CC Attribution-Share Alike 3.0 Unported <http://creativecommons.org/licenses/by-sa/3.0/>
 */

    $lang['create_private_ns'] = 'Créer les espaces privés des utilisateurs (vérifier soigneusement toutes les options avant l\'activation)?';
    $lang['use_name_string'] = 'Utiliser le nom complet de l\'utilisateurs au lieu du login pour son espace privé.';
    $lang['use_start_page'] = 'Utiliser le nom de page d\'accueil du wiki pour celle de chaque espace privé (sinon le nom de l\'espace privé sera utilisé).';
    $lang['users_namespace'] = 'Espace de nom sous lequel créer les espaces privés des utilisateurs.';
    $lang['group_by_name'] = 'Grouper les espaces privés des utilisateurs par la première lettre de leur nom ?';
    $lang['edit_before_create'] = 'Permettre aux utilisateurs d\'éditer la page d\'accueil de leur espace privé à sa création (fonctionnera uniquement si une page publique n\'est pas créée en même temps).';
    $lang['acl_all_private'] = 'Droits d\'accès pour le groupe @ALL sur les Espaces Privés';
    $lang['acl_all_private_o_0'] = 'Aucun (Défaut)';
    $lang['acl_all_private_o_1'] = 'Lecture';
    $lang['acl_all_private_o_2'] = 'Écriture';
    $lang['acl_all_private_o_4'] = 'Création';
    $lang['acl_all_private_o_8'] = 'Envoyer';
    $lang['acl_all_private_o_16'] = 'Effacer';
    $lang['acl_all_private_o_noacl'] = 'Pas de gestion automatique des droits';
    $lang['acl_user_private'] = 'Droits d\'accès pour le groupe @user sur les Espaces Privés';
    $lang['acl_user_private_o_0'] = 'Aucun (Défaut)';
    $lang['acl_user_private_o_1'] = 'Lecture';
    $lang['acl_user_private_o_2'] = 'Écriture';
    $lang['acl_user_private_o_4'] = 'Création';
    $lang['acl_user_private_o_8'] = 'Envoyer';
    $lang['acl_user_private_o_16'] = 'Effacer';
    $lang['acl_user_private_o_noacl'] = 'Pas de gestion automatique des droits';
    $lang['groups_private'] = 'Liste séparée par des virgules de groupes d\'utilisateurs concernés par la création d\'un espace privé (laisser vide pour appliquer les réglages ci-dessus à tous les utilisateurs).';
    $lang['create_public_page'] = 'Créer une page publique pour chaque utilisateur?';
    $lang['public_pages_ns'] = 'Espace de nom sous lequel créer les pages publiques.';
    $lang['acl_all_public'] = 'Droits d\'accès pour le groupe @ALL sur les Pages Publiques';
    $lang['acl_all_public_o_0'] = 'Aucun';
    $lang['acl_all_public_o_1'] = 'Lecture (Défaut)';
    $lang['acl_all_public_o_2'] = 'Écriture';
    $lang['acl_all_public_o_noacl'] = 'Pas de gestion automatique des droits';
    $lang['acl_user_public'] = 'Droits d\'accès pour le groupe @user sur les Pages Publiques';
    $lang['acl_user_public_o_0'] = 'Aucun';
    $lang['acl_user_public_o_1'] = 'Lecture (Défaut)';
    $lang['acl_user_public_o_2'] = 'Écriture';
    $lang['acl_user_public_o_noacl'] = 'Pas de gestion automatique des droits';
    $lang['groups_public'] = 'Liste séparée par des virgules de groupes d\'utilisateurs concernés par la création d\'une page publique (laisser vide pour appliquer les réglages ci-dessus à tous les utilisateurs).';
    $lang['templates_path'] = 'Chemin relatif depuis [<code>savedir</code>] où les modèles seront stockés (userhomepage_private.txt et userhomepage_public.txt). Exemples: <code>./pages/user</code> (permet d\'éditer les modèles depuis le wiki) ou <code>../lib/plugins/userhomepage</code> (pour plus de protecion ou pour les centraliser dans une ferme de wikis).';
    $lang['templatepath'] = 'Chemin vers le modèle de la version 3.0.4. Si le fichier existe, il sera utilisé comme source pour le modèle des pages d\'accueil des espaces privés (videz le chemin si vous ne le souhaitez pas).';
    $lang['acl_all_templates'] = 'Droits d\'accès pour le groupe @ALL sur les modèles (s\'ils sont stockés dans <code>data/pages...</code>)';
    $lang['acl_all_templates_o_0'] = 'Aucun';
    $lang['acl_all_templates_o_1'] = 'Lecture (Défaut)';
    $lang['acl_all_templates_o_2'] = 'Écriture';
    $lang['acl_all_templates_o_noacl'] = 'Pas de gestion automatique des droits';
    $lang['acl_user_templates'] = 'Droits d\'accès pour le groupe @user sur les modèles (s\'ils sont stockés dans <code>data/pages...</code>)';
    $lang['acl_user_templates_o_0'] = 'Aucun';
    $lang['acl_user_templates_o_1'] = 'Lecture (Défaut)';
    $lang['acl_user_templates_o_2'] = 'Écriture';
    $lang['acl_user_templates_o_noacl'] = 'Pas de gestion automatique des droits';
    $lang['no_acl'] = 'Aucun règlage automatique des droits d\'accès mais vous devrez nettoyer manuellement les règles déjà créées. Pensez à protéger les modèles.';
    $lang['redirection'] = 'Activer la  redirection (même désactivée, elle aura tout de même lieu lors de la création des pages).';
    $lang['action'] = 'Action à la première redirection vers la page publique après sa création (ou la page d\'accueil de l\'espace de nom privé).';
    $lang['action_o_edit'] = 'Editer (Défaut)';
    $lang['action_o_show'] = 'Afficher';
    $lang['userlink_replace'] = 'Activer le remplacement du lien interwiki [<code>Connecté en tant que</code>], selon les pages créées par Userhomepage (ne fonctionne que si l\'option <code>showuseras</code> est configurée pour le lien interwiki).';
    $lang['userlink_classes'] = 'Liste séparée par des espaces de classes CSS à appliquer aux liens de la chaîne [<code>Connecté en tant que</code>] (défaut: <code>interwiki iw_user wikilink1</code>).';
    $lang['userlink_fa'] = 'Utiliser des icônes Fontawesome au lieu d\'images (Fontawesome doit être installé par le thème ou un plugin) ?';
