<?php
/**
 * Revaa TTS — Widget auto-suffisant (inclusion directe dans un template).
 *
 * Usage : placer cette ligne à l'endroit voulu dans le template LifterLMS :
 *
 *   <?php include( WP_PLUGIN_DIR . '/revaa-tts/includes/widget-inline.php' ); ?>
 *
 * Ce fichier est totalement autonome : il embarque le CSS et le JS inline.
 * Il ne dépend ni de wp_head(), ni de wp_enqueue_scripts(), ni d'aucun hook.
 *
 * @package Revaa_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Revaa TTS Widget -->
<style>
#revaa-tts-widget{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#f5f5f5;border-left:4px solid #2271b1;border-radius:4px;padding:10px 16px;margin:16px 0;font-family:inherit;font-size:.9rem;color:#333;box-sizing:border-box}
#revaa-tts-widget button{display:inline-flex;align-items:center;gap:6px;background:#2271b1;color:#fff;border:none;border-radius:4px;padding:6px 14px;font-size:.875rem;font-weight:600;cursor:pointer;transition:background .15s ease,transform .1s ease;white-space:nowrap}
#revaa-tts-widget button:hover,#revaa-tts-widget button:focus-visible{background:#135e96;outline:2px solid #135e96;outline-offset:2px}
#revaa-tts-widget button:active{transform:scale(.96)}
#revaa-tts-stop{background:#757575!important;padding:6px 10px!important;font-size:1rem!important}
#revaa-tts-stop:hover,#revaa-tts-stop:focus-visible{background:#555!important}
#revaa-tts-widget label{font-size:.8rem;font-weight:600;color:#555;white-space:nowrap}
#revaa-tts-widget select{appearance:none;-webkit-appearance:none;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23555'/%3E%3C/svg%3E") no-repeat right 8px center;background-size:10px 6px;border:1px solid #c5c5c5;border-radius:4px;padding:5px 28px 5px 10px;font-size:.85rem;color:#333;cursor:pointer;min-width:80px;transition:border-color .15s ease}
#revaa-tts-widget select:hover,#revaa-tts-widget select:focus{border-color:#2271b1;outline:2px solid #2271b1;outline-offset:1px}
#revaa-tts-voice{max-width:220px}
#revaa-tts-progress{flex:1 1 200px;max-width:200px;height:6px;border-radius:3px;overflow:hidden;border:none;background:#ddd;appearance:none;-webkit-appearance:none}
#revaa-tts-progress::-webkit-progress-bar{background:#ddd;border-radius:3px}
#revaa-tts-progress::-webkit-progress-value{background:#2271b1;border-radius:3px;transition:width .3s ease}
#revaa-tts-progress::-moz-progress-bar{background:#2271b1;border-radius:3px}
.revaa-tts-unsupported{background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;padding:10px 16px;margin:16px 0;font-size:.875rem;color:#856404}
@media(max-width:600px){#revaa-tts-widget{flex-direction:column;align-items:flex-start;gap:10px}#revaa-tts-widget button,#revaa-tts-widget select{width:100%;box-sizing:border-box}#revaa-tts-voice{max-width:100%}#revaa-tts-progress{width:100%;max-width:100%;flex:0 0 auto}}
</style>

<div id="revaa-tts-widget" role="region" aria-label="Lecteur audio de la leçon">
  <button id="revaa-tts-play" aria-label="Lire">&#9654; Lire</button>
  <button id="revaa-tts-stop" aria-label="Arrêter">&#9209;</button>
  <label for="revaa-tts-speed">Vitesse</label>
  <select id="revaa-tts-speed">
    <option value="0.75">0.75×</option>
    <option value="1" selected>1×</option>
    <option value="1.25">1.25×</option>
    <option value="1.5">1.5×</option>
    <option value="2">2×</option>
  </select>
  <label for="revaa-tts-voice">Voix</label>
  <select id="revaa-tts-voice"></select>
  <progress id="revaa-tts-progress" value="0" max="100" aria-label="Progression de la lecture"></progress>
</div>

<script>
(function(){
'use strict';

var widget=document.getElementById('revaa-tts-widget');
if(!widget)return;

if(!('speechSynthesis'in window)){
  widget.style.display='none';
  var msg=document.createElement('p');
  msg.className='revaa-tts-unsupported';
  msg.innerHTML='🔇 <strong>Lecteur audio non supporté par ce navigateur.</strong><br>Navigateurs compatibles : <strong>Chrome</strong>, <strong>Edge</strong>, <strong>Safari</strong>, <strong>Firefox</strong>.';
  widget.parentNode.insertBefore(msg,widget);
  return;
}

var synth=window.speechSynthesis;
var btnPlay=document.getElementById('revaa-tts-play');
var btnStop=document.getElementById('revaa-tts-stop');
var selSpeed=document.getElementById('revaa-tts-speed');
var selVoice=document.getElementById('revaa-tts-voice');
var progress=document.getElementById('revaa-tts-progress');

/* ── Extraction du texte ── */
function getText(){
  // 1. Titre de la leçon — on tente plusieurs sélecteurs courants
  var titleEl = document.querySelector(
    '.llms-lesson-title, h1.entry-title, header h1, h1.page-title, h1'
  );
  var titleText = titleEl ? (titleEl.textContent||'').trim() : '';

  // 2. Corps du contenu
  var selectors=['.entry-content','.llms-lesson-content','article .wp-block-post-content','main'];
  var container=null;
  for(var i=0;i<selectors.length;i++){container=document.querySelector(selectors[i]);if(container)break;}
  if(!container)container=document.body;
  var clone=container.cloneNode(true);
  // Supprime les éléments à exclure ET les balises style/script
  // (textContent les lirait sinon si innerText échoue sur nœud détaché)
  ['#revaa-tts-widget','nav','header','footer','.llms-course-navigation',
   'style','script','noscript','iframe','svg'].forEach(function(s){
    clone.querySelectorAll(s).forEach(function(el){el.remove();});
  });
  var bodyText = (clone.textContent||clone.innerText||'').replace(/\s+/g,' ').trim();

  // 3. Concatène titre + contenu (séparés par une pause naturelle via ". ")
  return titleText ? titleText + '. ' + bodyText : bodyText;
}

/* ── Voix ── */
function populateVoices(){
  var voices=synth.getVoices();
  selVoice.innerHTML='';
  if(!voices.length){
    var o=document.createElement('option');o.textContent='Aucune voix disponible';o.disabled=true;
    selVoice.appendChild(o);selVoice.disabled=true;return;
  }
  selVoice.disabled=false;
  var fr=voices.filter(function(v){return v.lang.startsWith('fr');});
  var other=voices.filter(function(v){return!v.lang.startsWith('fr');});
  function makeOpt(v){var o=document.createElement('option');o.value=v.name;o.textContent=v.name+' ('+v.lang+')';return o;}
  if(fr.length){var g=document.createElement('optgroup');g.label='🇫🇷 Voix françaises';fr.forEach(function(v){g.appendChild(makeOpt(v));});selVoice.appendChild(g);}
  if(other.length){var g2=document.createElement('optgroup');g2.label='🌐 Autres voix';other.forEach(function(v){g2.appendChild(makeOpt(v));});selVoice.appendChild(g2);}
}
populateVoices();
if(typeof synth.onvoiceschanged!=='undefined')synth.onvoiceschanged=populateVoices;

/* ── État ── */
var utterance=null,isPaused=false,charIdx=0,totalLen=0,keepAlive=null;

function startKeepAlive(){
  stopKeepAlive();
  keepAlive=setInterval(function(){if(synth.speaking&&!isPaused){synth.pause();synth.resume();}},10000);
}
function stopKeepAlive(){if(keepAlive!==null){clearInterval(keepAlive);keepAlive=null;}}

function reset(){
  isPaused=false;charIdx=0;
  btnPlay.innerHTML='&#9654; Lire';btnPlay.setAttribute('aria-label','Lire');
  progress.value=0;stopKeepAlive();
}

function speak(text){
  synth.cancel();
  utterance=new SpeechSynthesisUtterance(text);
  totalLen=text.length;charIdx=0;
  utterance.rate=parseFloat(selSpeed.value)||1;
  var voices=synth.getVoices();
  var chosen=voices.find(function(v){return v.name===selVoice.value;});
  if(chosen){utterance.voice=chosen;utterance.lang=chosen.lang;}
  else if(voices.length){var fb=voices.find(function(v){return v.lang.startsWith('fr');})||voices[0];utterance.voice=fb;utterance.lang=fb.lang;}
  utterance.addEventListener('boundary',function(e){
    if(e.name==='word'||e.name==='sentence'){charIdx=e.charIndex;if(totalLen>0)progress.value=Math.round(charIdx/totalLen*100);}
  });
  utterance.addEventListener('end',reset);
  utterance.addEventListener('error',function(e){if(e.error!=='interrupted')console.warn('Revaa TTS:',e.error);reset();});
  synth.speak(utterance);startKeepAlive();
}

/* ── Bouton Play/Pause ── */
btnPlay.addEventListener('click',function(){
  if(synth.speaking){
    if(isPaused){synth.resume();isPaused=false;btnPlay.innerHTML='&#9646;&#9646; Pause';btnPlay.setAttribute('aria-label','Pause');startKeepAlive();}
    else{synth.pause();isPaused=true;stopKeepAlive();btnPlay.innerHTML='&#9654; Lire';btnPlay.setAttribute('aria-label','Lire');}
  }else{
    var text=getText().trim();if(!text)return;
    isPaused=false;btnPlay.innerHTML='&#9646;&#9646; Pause';btnPlay.setAttribute('aria-label','Pause');
    speak(text);
  }
});

/* ── Bouton Stop ── */
btnStop.addEventListener('click',function(){synth.cancel();reset();});

/* ── Changement vitesse ── */
selSpeed.addEventListener('change',function(){
  if(synth.speaking){var t=getText().trim();var r=t.slice(charIdx);synth.cancel();isPaused=false;speak(r);}
});

})();
</script>
<!-- /Revaa TTS Widget -->
