'use strict';
// Dobles de DOM y Web Speech: verifican lógica, no reconocimiento acústico real.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../frontend/assets/js/consultas-voz.js'), 'utf8');
let checks = 0;
function check(ok, label) { assert.ok(ok, label); checks++; }
function setup(options = {}) {
    const elements = {};
    for (const id of ['voice-query','voice-form','voice-start','voice-stop','voice-status','voice-read','voice-speak','voice-silence','voice-speech-status']) {
        elements[id] = { value: '', checked: false, disabled: ['voice-stop','voice-speak','voice-silence'].includes(id), hidden: true, textContent: '', attrs: {}, events: {},
            addEventListener(type, fn) { this.events[type] = fn; }, setAttribute(key, value) { this.attrs[key] = value; }, focus() { this.focused = true; },
            click() { if (!this.disabled && this.events.click) this.events.click(); }
        };
    }
    const instances = [];
    class Recognition {
        constructor() { if (options.constructorError) throw Error('No disponible'); this.starts=0;this.stops=0;this.aborts=0;instances.push(this); }
        start() { this.starts++; if(options.startError) throw Error('No permitido'); if(this.onstart)this.onstart(); }
        stop() { this.stops++; if(this.onend)this.onend(); }
        abort() { this.aborts++; if(this.onend)this.onend(); }
    }
    const timers = new Map();
    const speech = { cancelled:0, utterances:[], cancel(){this.cancelled++;}, getVoices(){return [{lang:'es-BO',name:'Español Bolivia'}];}, speak(u){this.utterances.push(u);} };
    const answer = { dataset:{spoken:'Se muestran dos citas autorizadas, como máximo diez.'} };
    const window = { isSecureContext:options.secure !== false, events:{},
        setTimeout(fn, ms) { timers.set(1,{fn,ms});return 1; }, clearTimeout(id) { timers.delete(id); }, addEventListener(type,fn){this.events[type]=fn;}
    };
    if(options.recognition !== false) window[options.webkit ? 'webkitSpeechRecognition':'SpeechRecognition'] = Recognition;
    if(options.synthesis !== false) { window.speechSynthesis = speech;window.SpeechSynthesisUtterance=class {constructor(text){this.text=text;}}; }
    const document = { querySelector(selector){return selector==='[data-voice-panel]' ? {} : (options.answer === false ? null:answer);},getElementById(id){return elements[id];} };
    vm.runInNewContext(source,{window,document},{timeout:1000});
    return {elements,window,instances,timers,speech};
}
let t=setup({recognition:false});
check(t.elements['voice-start'].disabled,'Sin API: micrófono desactivado');
check(t.elements['voice-status'].textContent.includes('Escribe tu consulta'),'Sin API: alternativa escrita');
check(typeof t.elements['voice-form'].events.submit==='function','Formulario escrito utilizable');
t=setup({secure:false});check(t.instances.length===0 && t.elements['voice-status'].textContent.includes('HTTPS'),'Contexto inseguro: no prepara micrófono');
t=setup({constructorError:true});check(t.elements['voice-start'].disabled && t.elements['voice-status'].textContent.includes('escrita'),'Error constructor: alternativa escrita');
t=setup({startError:true});t.elements['voice-start'].click();check(!t.elements['voice-start'].disabled && t.elements['voice-status'].textContent.includes('por escrito'),'Error start: permite recuperación y texto');
t=setup({webkit:true});const r=t.instances[0];
check(r.starts===0,'No solicita micrófono al cargar');
check(r.lang==='es-BO' && r.continuous===false && r.interimResults===false,'Idioma y captura de una frase');
t.elements['voice-start'].click();check(r.starts===1 && t.elements['voice-start'].disabled && !t.elements['voice-stop'].disabled,'Solo clic inicia y evita inicios duplicados');
check(t.timers.get(1).ms===12000,'Límite explícito 12 segundos');
r.onresult({resultIndex:0,results:[[{transcript:'¿Cuáles son mis próximas citas?'}]]});
check(t.elements['voice-query'].value==='¿Cuáles son mis próximas citas?' && t.elements['voice-query'].focused,'Transcripción visible y editable');
check(r.stops===1 && t.elements['voice-status'].textContent.includes('Todavía no se ha enviado'),'No envía consulta automáticamente');
check(t.timers.size===0 && t.elements['voice-stop'].disabled,'Fin libera controles y temporizador');
t.elements['voice-start'].click();t.elements['voice-stop'].click();check(r.aborts===1 && r.starts===2,'Detención manual sin reinicio permanente');
t.elements['voice-start'].click();t.timers.get(1).fn();check(r.aborts===2 && t.elements['voice-status'].textContent.includes('12 segundos'),'Tiempo máximo aborta micrófono');
r.onstart();check(r.aborts===3,'Inicio tardío después del timeout se aborta');
for(const error of ['not-allowed','service-not-allowed','audio-capture','no-speech','network','language-not-supported','aborted','unknown']){
    t.elements['voice-start'].click();r.onerror({error});check(!t.elements['voice-start'].disabled && t.elements['voice-stop'].disabled,'Error recuperable '+error);
}
check(t.speech.utterances.length===0,'No reproduce ni inicia grabación en segundo plano');
t.elements['voice-read'].checked=true;t.elements['voice-read'].events.change();t.elements['voice-speak'].click();
check(t.speech.utterances.length===1 && t.speech.utterances[0].lang==='es-BO','Lectura explícita con idioma');
check(t.speech.utterances[0].text==='Se muestran dos citas autorizadas, como máximo diez.','Lee solo resumen proporcionado por servidor');
const before=t.speech.cancelled;t.elements['voice-read'].checked=false;t.elements['voice-read'].events.change();
check(t.elements['voice-speak'].disabled && t.speech.cancelled>before,'Desactivar lectura cancela audio');
t.elements['voice-form'].events.submit();t.window.events.pagehide();check(t.speech.cancelled>before+1,'Enviar o salir detiene micrófono y audio');
t=setup({synthesis:false});check(t.elements['voice-read'].disabled && t.elements['voice-speech-status'].textContent.includes('visual'),'Sin síntesis conserva respuesta visual');
check(fs.readFileSync(require('node:path').join(__dirname,'../public/assets/js/consultas-voz.js'),'utf8')===source,'Recurso público coincide con fuente');
console.log(`${checks} comprobaciones JavaScript P10 con DOM/Web Speech simulados; sin micrófono ni servicios externos.`);
