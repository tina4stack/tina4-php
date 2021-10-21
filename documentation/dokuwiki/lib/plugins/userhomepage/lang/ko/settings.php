<?php

/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * 
 * @author Erial <erial2@gmail.com>
 */
$lang['create_private_ns']     = '사용자의 개인 이름공간 만들기 (활성화 하기 전에 모든 옵션을 재차 확인하십시오)';
$lang['use_name_string']       = '개인 이름공간에서 로그인명 대신 풀네임을 사용함.';
$lang['use_start_page']        = '각각의 개인 이름공간의 시작 문서를 위키의 시작 문서명을 따라 사용함 (미체크시 개인 이름공간 이름의 문서를 사용함)';
$lang['users_namespace']       = '사용자 이름공간 아래에 하부 이름공간이 생성되었습니다.';
$lang['group_by_name']         = '사용자 이름의 첫자로 그룹 사용자의 이름공간을 만드시겠습니까?';
$lang['edit_before_create']    = '계정 생성시 개인 이름공간의 시작문서 편집을 허용하시겠습니까 (공개 문서가 동시에 생성되지 않을 경우에만 작동합니다)';
$lang['acl_all_private']       = '개인 이름공간에대한 @ALL 그룹의 권한';
$lang['acl_all_private_o_0']   = '없음 (기본설정)';
$lang['acl_all_private_o_1']   = '읽기';
$lang['acl_all_private_o_2']   = '수정';
$lang['acl_all_private_o_4']   = '만들기';
$lang['acl_all_private_o_8']   = '업로드';
$lang['acl_all_private_o_16']  = '삭제';
$lang['acl_all_private_o_noacl'] = '자동 ACL 사용 안함';
$lang['acl_user_private']      = '개인 이름공간에대한 @user 그룹의 권한';
$lang['acl_user_private_o_0']  = '없음 (기본설정)';
$lang['acl_user_private_o_1']  = '읽기';
$lang['acl_user_private_o_2']  = '수정';
$lang['acl_user_private_o_4']  = '만들기';
$lang['acl_user_private_o_8']  = '업로드';
$lang['acl_user_private_o_16'] = '삭제';
$lang['acl_user_private_o_noacl'] = '자동 ACL 사용 안함';
$lang['groups_private']        = '개인 이름공간 생성에 참여할 수 있는 사용자 그룹의 목록을 콤마로 구분지어 입력해주십시오 (비워두면 상기설정은 모든 사용자에게 적용됩니다)';
$lang['create_public_page']    = '사용자의 공개 문서를 만들겠습니까?';
$lang['public_pages_ns']       = '공개 문서 아래의 이름공간이 만들어졌습니다.';
$lang['acl_all_public']        = '공개 문서에 대한 @ALL 그룹의 권한';
$lang['acl_all_public_o_0']    = '없음';
$lang['acl_all_public_o_1']    = '읽기 (기본설정)';
$lang['acl_all_public_o_2']    = '수정';
$lang['acl_all_public_o_noacl'] = '자동 ACL 사용 안함';
$lang['acl_user_public']       = '공개 문서에 대한 @user 그룹의 권한';
$lang['acl_user_public_o_0']   = '없음';
$lang['acl_user_public_o_1']   = '읽기 (기본설정)';
$lang['acl_user_public_o_2']   = '수정';
$lang['acl_user_public_o_noacl'] = '자동 ACL 사용 안함';
$lang['groups_public']         = '공개 문서 생성에 참여할 수 있는 사용자 그룹의 목록을 콤마로 구분지어 입력해주십시오 (비워두면 상기설정은 모든 사용자에게 적용됩니다)';
$lang['templates_path']        = '템플릿이 저장될 [<code>savedir</code>]와의 연관 위치 (userhomepage_private.txt 또는 userhomepage_public.txt). 예제: <code>./pages/user</code> 또는 <code>../lib/plugins/userhomepage</code>.';
$lang['templatepath']          = '버전 3.0.4 이후의 템플릿 위치. 만약 이 파일이 있으면 새로운 개인 이름공간의 시작문서를 만들 때 기본 양식 문서로 사용합니다. (사용하지 않으면 비워두세요)';
$lang['acl_all_templates']     = '템플릿에 대한 @ALL 그룹의 권한 (내용이 <code>data/pages...</code>에 저장될 경우)';
$lang['acl_all_templates_o_0'] = '없음';
$lang['acl_all_templates_o_1'] = '읽기 (기본설정)';
$lang['acl_all_templates_o_2'] = '수정';
$lang['acl_all_templates_o_noacl'] = '자동 ACL 사용 안함';
$lang['acl_user_templates']    = '템플릿에 대한 @user 그룹의 권한 (내용이 <code>data/pages...</code>에 저장될 경우)';
$lang['acl_user_templates_o_0'] = '없음';
$lang['acl_user_templates_o_1'] = '읽기 (기본설정)';
$lang['acl_user_templates_o_2'] = '수정';
$lang['acl_user_templates_o_noacl'] = '자동 ACL 사용 안함';
$lang['no_acl']                = '자동 ACL 설정을 전혀 사용하지 않습니다. 이미 생성된 것들은 수동으로 삭제해야 합니다. 템플릿 ACL 설정도 잊지 마세요.';
$lang['redirection']           = '자동 넘기기 활성화 (이 옵션을 비활성화 해도 문서 생성시에는 작동합니다)';
$lang['action']                = '공개 문서(또는 개인 이름공간의 시작문서)가 만들어진 후 사용자가 처음 접속할 때 진행할 처리 ';
$lang['action_o_edit']         = '수정 (기본설정)';
$lang['action_o_show']         = '보기';
$lang['userlink_replace']      = '위키 내부로 연결된 [<code>로그인한 사용자</code>]를 Userhomepage가 만든 문서로 대체하는걸 허용함 (<code>showuseras</code> 옵션이 위키 내부 연결로 설정된 경우에만 작동함)';
$lang['userlink_classes']      = '띄어쓰기로 구분된 [<code>로그인한 사용자</code>]의 위키 내부 연결에 사용할 CSS 클래스의 목록. (기본설정: <code>interwiki iw_user wikilink1</code>).';
$lang['userlink_fa']           = '이미지 대신에 Fontawesome 아이콘을 사용하시겠습니까? (Fontawesome 기능이 템플릿이나 플러그인으로 설치되어있어야합니다)';
