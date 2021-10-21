<?php

/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 *
 * @author Hideaki SAWADA <chuno@live.jp>
 */
$lang['create_private_ns']     = 'ユーザーの私用の名前空間の作成（有効化する前に全オプションを再度確認して下さい）';
$lang['use_name_string']       = '私用の名前空間名としてユーザー名の代わりに氏名を使用する。';
$lang['use_start_page']        = 'Wiki のスタートページ名を私用の名前空間のスタートページに使用する（無効の場合、名前空間名を使用）。';
$lang['users_namespace']       = 'ユーザーの名前空間を作成する名前空間';
$lang['group_by_name']         = 'ユーザー名の一文字目による users グループの名前空間';
$lang['edit_before_create']    = '私用の名前空間作成時にスタートページの編集を許可する（同時に公開ページを作成しない場合のみ）。';
$lang['acl_all_private']       = '私用の名前空間に対する @ALL グループのアクセス権限';
$lang['acl_all_private_o_0']   = '無し（デフォルト）';
$lang['acl_all_private_o_1']   = '読取';
$lang['acl_all_private_o_2']   = '編集';
$lang['acl_all_private_o_4']   = '作成';
$lang['acl_all_private_o_8']   = 'アップロード';
$lang['acl_all_private_o_16']  = '削除';
$lang['acl_all_private_o_noacl'] = '自動的にアクセス権限を与えない';
$lang['acl_user_private']      = '私用の名前空間に対する @user グループのアクセス権限';
$lang['acl_user_private_o_0']  = '無し（デフォルト）';
$lang['acl_user_private_o_1']  = '読取';
$lang['acl_user_private_o_2']  = '編集';
$lang['acl_user_private_o_4']  = '作成';
$lang['acl_user_private_o_8']  = 'アップロード';
$lang['acl_user_private_o_16'] = '削除';
$lang['acl_user_private_o_noacl'] = '自動的にアクセス権限を与えない';
$lang['groups_private']        = '私用の名前空間を作成するユーザーグループのカンマ区切り一覧（上記の設定を全ユーザーに適用する場合、空のままにします）';
$lang['create_public_page']    = 'ユーザーの公開ページの作成';
$lang['public_pages_ns']       = '公開ページを作成する名前空間';
$lang['acl_all_public']        = '公開ページに対する @ALL グループのアクセス権限';
$lang['acl_all_public_o_0']    = '無し';
$lang['acl_all_public_o_1']    = '読取（デフォルト）';
$lang['acl_all_public_o_2']    = '編集';
$lang['acl_all_public_o_noacl'] = '自動的にアクセス権限を与えない';
$lang['acl_user_public']       = '公開ページに対する @user グループのアクセス権限';
$lang['acl_user_public_o_0']   = '無し';
$lang['acl_user_public_o_1']   = '読取（デフォルト）';
$lang['acl_user_public_o_2']   = '編集';
$lang['acl_user_public_o_noacl'] = '自動的にアクセス権限を与えない';
$lang['groups_public']         = '公開ページを作成するユーザーグループのカンマ区切り一覧（上記の設定を全ユーザーに適用する場合、空のままにします）';
$lang['templates_path']        = 'テンプレートが保存される [<code>savedir</code>] からの相対パス（userhomepage_private.txt と userhomepage_public.txt）。
例： <code>./pages/user</code> または <code>../lib/plugins/userhomepage</code>';
$lang['templatepath']          = 'バージョン 3.0.4 から引き継いだテンプレートのパス。このファイルがある場合、私用の名前空間の新規スタートページテンプレートのデフォルト元として使用する（望まない場合は初期化する）。';
$lang['acl_all_templates']     = 'テンプレート対する @ALL グループのアクセス権限（<code>data/pages...</code>に置いた場合）';
$lang['acl_all_templates_o_0'] = '無し';
$lang['acl_all_templates_o_1'] = '読取（デフォルト）';
$lang['acl_all_templates_o_2'] = '編集';
$lang['acl_all_templates_o_noacl'] = '自動的にアクセス権限を与えない';
$lang['acl_user_templates']    = 'テンプレート対する @user グループのアクセス権限（<code>data/pages...</code>に置いた場合）';
$lang['acl_user_templates_o_0'] = '無し';
$lang['acl_user_templates_o_1'] = '読取（デフォルト）';
$lang['acl_user_templates_o_2'] = '編集';
$lang['acl_user_templates_o_noacl'] = '自動的にアクセス権限を与えない';
$lang['no_acl']                = '自動的にアクセス権限を与えませんが、これまでに手動で設定しらアクセス権限を削除する必要があります。
テンプレートにアクセス権限設定することを忘れないでください。';
$lang['redirection']           = 'リダイレクトを有効にする。（無効の場合でも、ページは作成します）';
$lang['action']                = '公開ページ（または私用の名前空間のスタートページ）作成直後のリダイレクト時の操作。';
$lang['action_o_edit']         = '編集（デフォルト）';
$lang['action_o_show']         = '表示';
$lang['userlink_replace']      = 'Userhomepage が作成したページに応じて、[<code>Logged in as</code>] インターウィキリンクの置換えを有効にします。（<code>showuseras</code> オプションがインターウィキリンクに設定されている場合のみ動作します）';
$lang['userlink_classes']      = '[<code>Logged in as</code>] インターウィキリンクに適用する CSS クラスのスペース区切り一覧（デフォルト： <code>interwiki iw_user wikilink1</code>）';
$lang['userlink_fa']           = '画像の代わりに Fontawesome アイコンを使用しますか（テンプレートやプラグインには Fontawesome をインストールする必要がある）？';
