<?PHP
/* Copyright 2005-2021, Lime Technology
 * Copyright 2012-2021, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
// add translations
$_SERVER['REQUEST_URI'] = '';
require_once "$docroot/webGui/include/Translations.php";
require_once "$docroot/webGui/include/Helpers.php";

if (isset($_POST['mode'])) {
  switch ($_POST['mode']) {
  case 'upload':
    $size = 'stop';
    $file = validname(htmlspecialchars_decode(rawurldecode($_POST['file'])));
    if ($file) {
      if (($_POST['start']==0 || $_POST['cancel']==1) && file_exists($file)) unlink($file);
      if ($_POST['cancel']==0) $size = file_put_contents($file,base64_decode(explode(';base64,',$_POST['data'])[1]),FILE_APPEND);
    }
    die($size);
  case 'calc':
    extract(parse_plugin_cfg('dynamix',true));
    $source = explode("\n",htmlspecialchars_decode(rawurldecode($_POST['source'])));
    [$null,$root,$main,$rest] = my_explode('/',$source[0],4);
    if ($root=='mnt' && in_array($main,['user','user0'])) {
      $disks = (array)parse_ini_file('state/disks.ini',true);
      $tag = implode('|',array_merge(['disk'],pools_filter($disks)));
      $loc = array_filter(explode(',',preg_replace("/($tag)/",',$1',exec("shopt -s dotglob; getfattr --no-dereference --absolute-names --only-values -n system.LOCATIONS ".quoted($source)."/* 2>/dev/null"))));
      natcasesort($loc);
      $loc = implode(', ',array_unique($loc));
    } else $loc = $root=='mnt' ? ($main ?: '---') : ($root=='boot' ? _('flash') : '---');
    $awk = "awk 'BEGIN{ORS=\" \"}/Number of files|Total file size/{if(\$5==\"(reg:\")print \$4,\$8;if(\$5==\"(dir:\")print \$4,\$6;if(\$3==\"size:\")print \$4}'";
    [$files,$dirs,$size] = explode(' ',str_replace([',',')'],'',exec("rsync --stats -naI ".quoted($source)." /var/tmp 2>/dev/null|$awk")));
    $files -= $dirs;
    $text   = [];
    $text[] = _('Name').": ".implode(', ',array_map('basename',$source));
    $text[] = _('Location').": ".$loc;
    $text[] = _('Last modified').': '.my_age(max(array_map('filemtime',$source)));
    $text[] = _('Total occupied space').": ".my_scale($size,$unit)." $unit";
    $text[] = sprintf(_("in %s folder".($dirs==1?'':'s')." and %s file".($files==1?'':'s')),my_number($dirs),my_number($files));
    die('<div style="text-align:left;margin-left:56px">'.implode('<br>',$text).'</div>');
  }
}
function write(&$rows) {
  if ($score = count($rows)) echo '<tbody>',array_map(function($row){echo gzinflate($row);},$rows),'</tbody>';
  $rows = $score;
}
function validdir($dir) {
  $path = realpath($dir);
  $root = explode('/',$path)[1] ?? '';
  return in_array($root,['mnt','boot']) ? $path : '';
}
function validname($name) {
  $path = realpath(dirname($name));
  $root = explode('/',$path)[1] ?? '';
  return in_array($root,['mnt','boot']) ? $path.'/'.basename($name) : '';
}
function escape($name) {return escapeshellarg(validname($name));}
function quoted($name) {return is_array($name) ? implode(' ',array_map('escape',$name)) : escape($name);}

function escapeQuote($data) {
  return str_replace('"','&#34;',$data);
}
function age($number,$time) {
  return sprintf(_('%s '.($number==1 ? $time : $time.'s').' ago'),$number);
}
function my_age($time) {
  $age = new DateTime('@'.$time);
  $age = date_create('now')->diff($age);
  if ($age->y > 0) return age($age->y,'year');
  if ($age->m > 0) return age($age->m,'month');
  if ($age->d > 0) return age($age->d,'day');
  if ($age->h > 0) return age($age->h,'hour');
  if ($age->i > 0) return age($age->i,'minute');
  return age($age->s,'second');
}
function parent_link() {
  global $dir,$path,$block;
  return in_array(dirname($dir),$block)||dirname($dir)==$dir ? '' : '<a href="/'.$path.'?dir='.rawurlencode(htmlspecialchars(dirname($dir))).'">'._('Parent Directory').'</a>';
}
function my_devs(&$devs,$name,$menu) {
  global $disks,$lock;
  $text = []; $i = 0;
  foreach ($devs as $dev) {
    if ($lock=='---') {
      $text[$i] = '<a class="info" onclick="return false"><i class="lock fa fa-fw fa-hdd-o grey-text"></i></a>&nbsp;---';
    } else {
      switch ($disks[$dev]['luksState']) {
        case 0: $text[$i] = '<a class="info" onclick="return false"><i class="lock fa fa-fw fa-unlock-alt grey-text"></i><span>'._('Not encrypted').'</span></a>'; break;
        case 1: $text[$i] = '<a class="info" onclick="return false"><i class="lock fa fa-fw fa-unlock-alt green-text"></i><span>'._('Encrypted and unlocked').'</span></a>'; break;
        case 2: $text[$i] = '<a class="info" onclick="return false"><i class="lock fa fa-fw fa-lock red-text"></i><span>'._('Locked: missing encryption key').'</span></a>'; break;
        case 3: $text[$i] = '<a class="info" onclick="return false"><i class="lock fa fa-fw fa-lock red-text"></i><span>'._('Locked: wrong encryption key').'</span></a>'; break;
       default: $text[$i] = '<a class="info" onclick="return false"><i class="lock fa fa-fw fa-lock red-text"></i><span>'._('Locked: unknown error').'</span></a>'; break;
      }
      $root = $dev=='flash' ? "/boot/$name" : "/mnt/$dev/$name";
      $text[$i] .= '&nbsp;<span id="device_'.$i.'" class="hand" onclick="'.$menu.'(\''.$root.'\','.$i.')" oncontextmenu="'.$menu.'(\''.$root.'\','.$i.');return false">'.compress($dev,8,0).'</span>';
    }
    $i++;
  }
  return implode(', ',$text);
}
$dir = validdir(htmlspecialchars_decode(rawurldecode($_GET['dir'])));
if (!$dir) {echo '<tbody><tr><td></td><td></td><td colspan="7">',_('Invalid path'),'</td></tr></tbody>'; exit;}

extract(parse_plugin_cfg('dynamix',true));
$disks = parse_ini_file('state/disks.ini',true);
$path  = unscript($_GET['path']);
$block = empty($_GET['block']) ? ['/','/mnt','/mnt/user'] : ['/'];
$fmt   = "%F {$display['time']}";
$dirs  = $files = [];
$total = $objs = 0;
[$null,$root,$main,$rest] = my_explode('/',$dir,4);
$user  = $root=='mnt' && in_array($main,['user','user0']);
$lock  = $root=='mnt' ? ($main ?: '---') : ($root=='boot' ? _('flash') : '---');
$isshare = $root=='mnt' && (!$main || !$rest);
$folder = $lock=='---' ? _('DEVICE') : ($isshare ? _('SHARE') : _('FOLDER'));

if ($user) {
  $tag = implode('|',array_merge(['disk'],pools_filter($disks)));
  $set = explode(';',str_replace(',;',',',preg_replace("/($tag)/",';$1',exec("shopt -s dotglob; getfattr --no-dereference --absolute-names --only-values -n system.LOCATIONS ".escapeshellarg($dir)."/* 2>/dev/null"))));
}
$stat = popen("shopt -s dotglob; stat -L -c'%F|%n|%U|%A|%s|%Y' ".escapeshellarg($dir)."/* 2>/dev/null",'r');
while (($row = fgets($stat))!==false) {
  [$type,$name,$owner,$perm,$size,$time] = my_explode('|',$row,6);
  $objs++; $loc = $user ? $set[$objs] : $lock;
  $ext  = strtolower(pathinfo($name,PATHINFO_EXTENSION));
  $devs = explode(',',$loc);
  $dev  = explode('/',$name,4);
  $tag  = count($devs)>1 ? 'warning' : '';
  $text = [];
  if ($row[0]=='d') {
    $text[] = '<tr><td><i id="check_'.$objs.'" class="fa fa-fw fa-square-o" onclick="selectOne(this.id)"></i></td>';
    $text[] = '<td data=""><div class="icon-dir"></div></td>';
    $text[] = '<td><a id="name_'.$objs.'" oncontextmenu="folderContextMenu(this.id,\'right\');return false" href="/'.$path.'?dir='.rawurlencode(htmlspecialchars($name)).'">'.htmlspecialchars(basename($name)).'</a></td>';
    $text[] = '<td id="owner_'.$objs.'">'.$owner.'</td>';
    $text[] = '<td id="perm_'.$objs.'">'.$perm.'</td>';
    $text[] = '<td data="0">&lt;'.$folder.'&gt;</td>';
    $text[] = '<td data="'.$time.'"><span class="my_time">'.my_time($time,$fmt).'</span><span class="my_age" style="display:none">'.my_age($time).'</span></td>';
    $text[] = '<td class="loc">'.my_devs($devs,$dev[3]??$dev[2],'deviceFolderContextMenu').'</td>';
    $text[] = '<td><i id="row_'.$objs.'" data="'.escapeQuote($name).'" type="d" class="fa fa-plus-square-o" onclick="folderContextMenu(this.id,\'both\')" oncontextmenu="folderContextMenu(this.id,\'both\');return false">...</i></td></tr>';
    $dirs[] = gzdeflate(implode($text));
  } else {
    $text[] = '<tr><td><i id="check_'.$objs.'" class="fa fa-fw fa-square-o" onclick="selectOne(this.id)"></i></td>';
    $text[] = '<td class="ext" data="'.$ext.'"><div class="icon-file icon-'.$ext.'"></div></td>';
    $text[] = '<td id="name_'.$objs.'" class="'.$tag.'" oncontextmenu="fileContextMenu(this.id,\'right\');return false">'.htmlspecialchars(basename($name)).'</td>';
    $text[] = '<td id="owner_'.$objs.'" class="'.$tag.'">'.$owner.'</td>';
    $text[] = '<td id="perm_'.$objs.'" class="'.$tag.'">'.$perm.'</td>';
    $text[] = '<td data="'.$size.'" class="'.$tag.'">'.my_scale($size,$unit).' '.$unit.'</td>';
    $text[] = '<td data="'.$time.'" class="'.$tag.'"><span class="my_time">'.my_time($time,$fmt).'</span><span class="my_age" style="display:none">'.my_age($time).'</span></td>';
    $text[] = '<td class="loc '.$tag.'">'.my_devs($devs,$dev[3]??$dev[2],'deviceFileContextMenu').'</td>';
    $text[] = '<td><i id="row_'.$objs.'" data="'.escapeQuote($name).'" type="f" class="fa fa-plus-square-o" onclick="fileContextMenu(this.id,\'both\')" oncontextmenu="fileContextMenu(this.id,\'both\');return false">...</i></td></tr>';
    $files[] = gzdeflate(implode($text));
    $total += $size;
  }
}
pclose($stat);
if ($link = parent_link()) echo '<tbody class="tablesorter-infoOnly"><tr><td></td><td><div><img src="/webGui/icons/folderup.png"></div></td><td>',$link,'</td><td colspan="6"></td></tr></tbody>';
echo write($dirs),write($files),'<tfoot><tr><td></td><td></td><td colspan="7">',$objs,' ',_('object'.($objs==1?'':'s')),': ',$dirs,' ',_('director'.($dirs==1?'y':'ies')),', ',$files,' ',_('file'.($files==1?'':'s')),' (',my_scale($total,$unit),' ',$unit,' ',_('total'),')</td></tr></tfoot>';
?>
