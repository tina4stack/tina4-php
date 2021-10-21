<?php
/**
 * DokuWiki Plugin konsole (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Fabrice DEJAIGHER <fabrice@chtiland.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC'))
{
	die();
}

if (!defined('DOKU_LF'))
{
	define('DOKU_LF', "\n");
}
if (!defined('DOKU_TAB'))
{
	define('DOKU_TAB', "\t");
}
if (!defined('DOKU_PLUGIN'))
{
	define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
}

require_once DOKU_PLUGIN.'syntax.php';

class syntax_plugin_konsole extends DokuWiki_Syntax_Plugin
{

    var $types_user = array(
	'user' => 'konsoleuser',
	'root' => 'konsoleroot',
	'admin' => 'konsoleroot'
    );

    var $type_defaut = 'konsoleuser';

    var $motsclefs = array('alias','apropos','awk','basename','bash','bc','bg','builtin','bzip2','cal','cat','cd','cfdisk','chgrp','chmod','chown','chroot',
			'cksum','clear','cmp','comm','command','cp','cron','crontab','csplit','cut','date','dc','dd','ddrescue','declare','df',
			'diff','diff3','dig','dir','dircolors','dirname','dirs','du','echo','egrep','eject','enable','env','ethtool','eval',
			'exec','exit','expand','export','expr','false','fdformat','fdisk','fg','fgrep','file','find','fmt','fold','format',
			'free','fsck','ftp','gawk','getopts','grep','groups','gzip','hash','head','history','hostname','id','ifconfig',
			'import','install','join','kill','less','let','ln','local','locate','logname','logout','look','lpc','lpr','lprint',
			'lprintd','lprintq','lprm','ls','lsof','make','man','mkdir','mkfifo','mkisofs','mknod','more','mount','mtools',
			'mv','netstat','nice','nl','nohup','nslookup','open','op','passwd','paste','pathchk','ping','popd','pr','printcap',
			'printenv','printf','ps','pushd','pwd','quota','quotacheck','quotactl','ram','rcp','read','readonly','renice',
			'remsync','rm','rmdir','rsync','screen','scp','sdiff','sed','select','seq','set','sftp','shift','shopt','shutdown',
			'sleep','sort','source','split','ssh','strace','su','sudo','sum','symlink','sync','tail','tar','tee','test','time',
			'times','touch','top','traceroute','trap','tr','true','tsort','tty','type','ulimit','umask','umount','unalias',
			'uname','unexpand','uniq','units','unset','unshar','useradd','usermod','users','uuencode','uudecode','v','vdir',
			'vi','watch','wc','whereis','which','who','whoami','Wget','xargs','yes');

    public function getType()
	{
        return 'container';
    }

    public function getPType()
	{
        return 'normal';
    }
    public function getAllowedTypes()
	{
        return array('container','substition','protected','disabled','formatting','paragraphs');
    }

    public function getSort()
	{
        return 195;
    }


    public function connectTo($mode)
	{
        $this->Lexer->addEntryPattern('<konsole.*?>(?=.*?</konsole>)',$mode,'plugin_konsole');
    }

    public function postConnect()
	{
        $this->Lexer->addExitPattern('</konsole>','plugin_konsole');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
	{
		switch ($state)
		{
			case DOKU_LEXER_ENTER : // Type
				$type_user = strtolower(trim(substr($match,8,-1)));

				foreach ($this->types_user as $type_possible => $classe)
				{
					if($type_user == $type_possible)
					{
						$type_user = strtoupper($type_user);
						$retour = $classe.','.$type_user;
						return array($state,$retour);
					}
				}
				return array($state,$this->type_defaut);



			case DOKU_LEXER_UNMATCHED :	// Contenu du terminal
				$divreturn = $match;

				foreach ($this->motsclefs as $motclef)
				{
					$motclef_hi = '<b>'.$motclef.'</b>';
					$divreturn = str_replace(" $motclef ", " $motclef_hi ", $divreturn);
					$divreturn = preg_replace("/\s$motclef$/", " $motclef_hi", $divreturn);
				}


				return array($state,$divreturn);



			default : // Sinon
				return array($state);


		}


    }

    public function render($mode, Doku_Renderer $renderer, $indata)
	{
		list($state, $data) = $indata;

		if($mode == 'xhtml')
		{
			switch ($state)
			{
				case DOKU_LEXER_ENTER :
					$arr_retour = explode(',',$data);
					$nom_user = $arr_retour[1];
							if(empty($nom_user))
							{
								$nom_user='USER';
							}
					$classe = $arr_retour[0];
					$renderer->doc.= '<div class="konsole">';
					$renderer->doc.= '<div class="konsole_top_left"></div><div class="konsole_top">'.$nom_user.'</div><div class="konsole_top_right"></div>';
					$renderer->doc .= '<div class="konsole_left"></div><div class="'.$classe.'">';
				break;

				case DOKU_LEXER_UNMATCHED :
					$renderer->doc .= $data;
				break;

				case DOKU_LEXER_EXIT :

					$renderer->doc .= '</div>'; // KonsoleUSER/ROOT
					$renderer->doc.='<div class="konsole_right"></div>';
					$renderer->doc.= '<div class="konsole_bottom_left"></div><div class="konsole_bottom"></div><div class="konsole_bottom_right"></div>';
					$renderer->doc.='</div>'; // konsole

				break;
			}

			return true;
		}
		else
		{
			return false;
		}


    }
}
