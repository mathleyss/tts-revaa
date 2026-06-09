<?php
/**
 * Revaa TTS — Widget auto-suffisant (inclusion directe dans un template).
 *
 * Usage dans le template LifterLMS :
 *   include( WP_PLUGIN_DIR . '/revaa-tts/includes/widget-inline.php' );
 *
 * Autonome : CSS et JS embarqués inline.
 * Aucune dépendance à wp_head() ou wp_enqueue_scripts().
 *
 * @package Revaa_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Revaa TTS Widget -->
<style>
/* Conteneur principal — colonne pour séparer la rangée boutons de la rangée vitesse */
#revaa-tts-widget{
  display:flex;flex-direction:column;gap:10px;
  background:#f5f5f5;border-left:4px solid #2271b1;border-radius:4px;
  padding:10px 16px;margin:16px 0;
  font-family:inherit;font-size:.9rem;color:#333;box-sizing:border-box;
}
/* Rangée 1 : boutons + sélecteur voix */
.revaa-tts-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
/* Rangée 2 : label Vitesse + sélecteur vitesse */
.revaa-tts-speed-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
/* Boutons */
#revaa-tts-widget button{
  background:#2271b1;color:#fff;border:none;border-radius:4px;
  padding:6px 16px;font-size:.875rem;font-weight:600;cursor:pointer;
  transition:background .15s ease,transform .1s ease;white-space:nowrap;
}
#revaa-tts-widget button:hover,#revaa-tts-widget button:focus-visible{background:#135e96;outline:2px solid #135e96;outline-offset:2px;}
#revaa-tts-widget button:active{transform:scale(.96);}
#revaa-tts-stop{background:#757575!important;}
#revaa-tts-stop:hover,#revaa-tts-stop:focus-visible{background:#555!important;}
/* Labels */
#revaa-tts-widget label{font-size:.8rem;font-weight:600;color:#555;white-space:nowrap;}
/* Selects */
#revaa-tts-widget select{
  appearance:none;-webkit-appearance:none;
  background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23555'/%3E%3C/svg%3E") no-repeat right 8px center;
  background-size:10px 6px;border:1px solid #c5c5c5;border-radius:4px;
  padding:5px 28px 5px 10px;font-size:.85rem;color:#333;cursor:pointer;
  min-width:80px;transition:border-color .15s ease;
}
#revaa-tts-widget select:hover,#revaa-tts-widget select:focus{border-color:#2271b1;outline:2px solid #2271b1;outline-offset:1px;}
#revaa-tts-voice{max-width:220px;}
/* Notice navigateur incompatible */
.revaa-tts-unsupported{
  background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;
  padding:10px 16px;margin:16px 0;font-size:.875rem;color:#856404;
}
/* Mobile */
@media(max-width:600px){
  .revaa-tts-row,.revaa-tts-speed-row{flex-direction:column;align-items:flex-start;gap:8px;}
  #revaa-tts-widget button,#revaa-tts-widget select{width:100%;box-sizing:border-box;}
  #revaa-tts-voice{max-width:100%;}
}
</style>

<div id="revaa-tts-widget" role="region" aria-label="Lecteur audio de la leçon">
  <div class="revaa-tts-row">
    <button id="revaa-tts-play">Lire</button>
    <button id="revaa-tts-stop">Arrêter</button>
    <label for="revaa-tts-voice">Voix</label>
    <select id="revaa-tts-voice"></select>
  </div>
  <div class="revaa-tts-speed-row">
    <label for="revaa-tts-speed">Vitesse</label>
    <select id="revaa-tts-speed">
      <option value="0.75">0.75×</option>
      <option value="1" selected>1×</option>
      <option value="1.25">1.25×</option>
      <option value="1.5">1.5×</option>
      <option value="2">2×</option>
    </select>
  </div>
</div>

<script>
(function(){
'use strict';

var widget=document.getElementById('revaa-tts-widget');
if(!widget)return;

/* ── Support navigateur ── */
if(!('speechSynthesis'in window)){
  widget.style.display='none';
  var msg=document.createElement('p');
  msg.className='revaa-tts-unsupported';
  msg.innerHTML='🔇 <strong>Lecteur audio non supporté par ce navigateur.</strong><br>Navigateurs compatibles : <strong>Chrome</strong>, <strong>Edge</strong>, <strong>Safari</strong>, <strong>Firefox</strong>.';
  widget.parentNode.insertBefore(msg,widget);
  return;
}

var synth   = window.speechSynthesis;
var btnPlay = document.getElementById('revaa-tts-play');
var btnStop = document.getElementById('revaa-tts-stop');
var selSpeed= document.getElementById('revaa-tts-speed');
var selVoice= document.getElementById('revaa-tts-voice');

/* ── Extraction du texte ── */
function getText(){
  // Titre de la leçon
  var titleEl=document.querySelector('.llms-lesson-title, h1.entry-title, header h1, h1.page-title, h1');
  var titleText=titleEl?(titleEl.textContent||'').trim():'';
  // Corps du contenu
  var selectors=['.entry-content','.llms-lesson-content','article .wp-block-post-content','main'];
  var container=null;
  for(var i=0;i<selectors.length;i++){container=document.querySelector(selectors[i]);if(container)break;}
  if(!container)container=document.body;
  var clone=container.cloneNode(true);
  ['#revaa-tts-widget','nav','header','footer','.llms-course-navigation',
   'style','script','noscript','iframe','svg'].forEach(function(s){
    clone.querySelectorAll(s).forEach(function(el){el.remove();});
  });
  var bodyText=(clone.textContent||clone.innerText||'').replace(/\s+/g,' ').trim();
  return titleText?titleText+'. '+bodyText:bodyText;
}

/* ── Liste des voix (françaises uniquement) ── */
function populateVoices(){
  var voices=synth.getVoices().filter(function(v){return v.lang.startsWith('fr');});
  selVoice.innerHTML='';
  if(!voices.length){
    var o=document.createElement('option');o.textContent='Aucune voix française disponible';o.disabled=true;
    selVoice.appendChild(o);selVoice.disabled=true;return;
  }
  selVoice.disabled=false;
  function makeOpt(v){var o=document.createElement('option');o.value=v.name;o.textContent=v.name+' ('+v.lang+')';return o;}
  voices.forEach(function(v){selVoice.appendChild(makeOpt(v));});
}
populateVoices();
if(typeof synth.onvoiceschanged!=='undefined')synth.onvoiceschanged=populateVoices;

/* ── État interne ── */
var isPaused=false, charIdx=0, totalLen=0, keepAlive=null;

function startKeepAlive(){
  stopKeepAlive();
  keepAlive=setInterval(function(){if(synth.speaking&&!isPaused){synth.pause();synth.resume();}},10000);
}
function stopKeepAlive(){if(keepAlive!==null){clearInterval(keepAlive);keepAlive=null;}}

function reset(){
  isPaused=false;charIdx=0;
  btnPlay.textContent='Lire';btnPlay.setAttribute('aria-label','Lire');
  stopKeepAlive();
}

/* ── Lancement de la synthèse ── */
function speak(text){
  synth.cancel();
  var utt=new SpeechSynthesisUtterance(text);
  totalLen=text.length;charIdx=0;
  utt.rate=parseFloat(selSpeed.value)||1;
  // Applique la voix sélectionnée
  var voices=synth.getVoices();
  var chosen=voices.find(function(v){return v.name===selVoice.value;});
  if(chosen){utt.voice=chosen;utt.lang=chosen.lang;}
  else if(voices.length){var fb=voices.find(function(v){return v.lang.startsWith('fr');})||voices[0];utt.voice=fb;utt.lang=fb.lang;}
  // Suivi de position (pour reprendre au bon endroit si vitesse/voix change)
  utt.addEventListener('boundary',function(e){
    if(e.name==='word'||e.name==='sentence')charIdx=e.charIndex;
  });
  utt.addEventListener('end',reset);
  utt.addEventListener('error',function(e){if(e.error!=='interrupted')console.warn('Revaa TTS:',e.error);reset();});
  synth.speak(utt);
  startKeepAlive();
}

/* ── Play / Pause ── */
btnPlay.addEventListener('click',function(){
  if(synth.speaking){
    if(isPaused){
      synth.resume();isPaused=false;
      btnPlay.textContent='Pause';btnPlay.setAttribute('aria-label','Pause');
      startKeepAlive();
    }else{
      synth.pause();isPaused=true;stopKeepAlive();
      btnPlay.textContent='Lire';btnPlay.setAttribute('aria-label','Lire');
    }
  }else{
    var text=getText().trim();if(!text)return;
    isPaused=false;
    btnPlay.textContent='Pause';btnPlay.setAttribute('aria-label','Pause');
    speak(text);
  }
});

/* ── Stop ── */
btnStop.addEventListener('click',function(){synth.cancel();reset();});

/* ── Changement de vitesse (reprend à la position courante) ── */
selSpeed.addEventListener('change',function(){
  if(synth.speaking){
    var remaining=getText().trim().slice(charIdx);
    isPaused=false;speak(remaining);
    btnPlay.textContent='Pause';btnPlay.setAttribute('aria-label','Pause');
  }
});

/* ── Changement de voix (reprend à la position courante) ── */
selVoice.addEventListener('change',function(){
  if(synth.speaking||isPaused){
    var remaining=getText().trim().slice(charIdx);
    isPaused=false;speak(remaining);
    btnPlay.textContent='Pause';btnPlay.setAttribute('aria-label','Pause');
  }
});

})();
</script>
<!-- /Revaa TTS Widget -->
