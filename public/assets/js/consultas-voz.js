'use strict';
(() => {
    const panel = document.querySelector('[data-voice-panel]');
    if (!panel) return;
    const input = document.getElementById('voice-query');
    const form = document.getElementById('voice-form');
    const start = document.getElementById('voice-start');
    const stop = document.getElementById('voice-stop');
    const status = document.getElementById('voice-status');
    const read = document.getElementById('voice-read');
    const speak = document.getElementById('voice-speak');
    const silence = document.getElementById('voice-silence');
    const speechStatus = document.getElementById('voice-speech-status');
    const answer = document.querySelector('[data-voice-answer]');
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let listening = false;
    let accepting = false;
    let timer = null;
    const idle = () => {
        listening = false;
        start.disabled = !recognition;
        start.setAttribute('aria-pressed', 'false');
        stop.disabled = true;
        window.clearTimeout(timer);
    };
    const cancelSpeech = () => {
        if (window.speechSynthesis) window.speechSynthesis.cancel();
        if (silence) silence.disabled = true;
    };
    const abortRecognition = () => {
        accepting = false;
        if (recognition && listening) recognition.abort();
        idle();
    };
    start.hidden = false;
    stop.hidden = false;
    if (!Recognition || !window.isSecureContext) {
        start.disabled = true;
        status.textContent = !window.isSecureContext
            ? 'El micrófono requiere HTTPS o localhost. La consulta escrita está disponible.'
            : 'Este navegador no ofrece reconocimiento de voz. Escribe tu consulta y pulsa Consultar en PostgreSQL.';
    } else {
        try {
            recognition = new Recognition();
            recognition.lang = 'es-BO';
            recognition.continuous = false;
            recognition.interimResults = false;
            recognition.maxAlternatives = 1;
            recognition.onstart = () => {
                if (!accepting || !listening) { recognition.abort(); return; }
                status.textContent = 'Escuchando en español de Bolivia… Di una consulta breve. Puedes detener el micrófono.';
            };
            recognition.onresult = (event) => {
                if (!accepting) return;
                const result = event.results[event.resultIndex || 0];
                const transcript = result && result[0] && result[0].transcript;
                if (typeof transcript !== 'string' || !transcript.trim()) {
                    status.textContent = 'No se reconoció una frase. Puedes repetir o escribir la consulta.';
                    return;
                }
                input.value = Array.from(transcript.trim()).slice(0, 240).join('');
                status.textContent = 'Transcripción lista. Revísala y pulsa Consultar en PostgreSQL. Todavía no se ha enviado.';
                input.focus();
                recognition.stop();
            };
            recognition.onerror = (event) => {
                const errors = {
                    'not-allowed': 'No se autorizó el micrófono o el servicio de reconocimiento. Revisa los permisos del navegador o escribe la consulta.',
                    'service-not-allowed': 'El navegador no permite usar el servicio de reconocimiento. La alternativa escrita sigue disponible.',
                    'audio-capture': 'No se encontró un micrófono disponible. Conéctalo o escribe tu consulta.',
                    'no-speech': 'No se detectó voz. Puedes repetir o escribir la consulta.',
                    'network': 'El servicio de reconocimiento no pudo conectarse. Puedes consultar por escrito.',
                    'language-not-supported': 'El servicio no admite es-BO. Puedes consultar por escrito.',
                    'aborted': 'Micrófono detenido. Puedes consultar por escrito.'
                };
                accepting = false;
                status.textContent = errors[event.error] || 'No se pudo reconocer la voz. Puedes consultar por escrito.';
                idle();
            };
            recognition.onnomatch = () => { status.textContent = 'No se entendió la consulta. Puedes repetir o escribirla.'; };
            recognition.onend = () => {
                accepting = false;
                if (status.textContent.startsWith('Escuchando') || status.textContent.startsWith('Iniciando')) {
                    status.textContent = 'El micrófono se detuvo. Revisa el texto o escribe tu consulta.';
                }
                idle();
            };
            start.addEventListener('click', () => {
                if (listening) return;
                cancelSpeech();
                try {
                    listening = true;
                    accepting = true;
                    start.disabled = true;
                    start.setAttribute('aria-pressed', 'true');
                    stop.disabled = false;
                    status.textContent = 'Iniciando micrófono. Autoriza el acceso solo si deseas dictar.';
                    recognition.start(); // Único lugar que solicita el micrófono: clic explícito.
                    timer = window.setTimeout(() => {
                        abortRecognition();
                        status.textContent = 'Se alcanzó el límite de 12 segundos. Revisa el texto o vuelve a intentarlo.';
                    }, 12000);
                } catch (_) {
                    accepting = false;
                    idle();
                    status.textContent = 'No se pudo iniciar el micrófono. Puedes consultar por escrito.';
                }
            });
            stop.addEventListener('click', () => {
                abortRecognition();
                status.textContent = 'Micrófono detenido. El texto escrito se conserva.';
            });
        } catch (_) {
            recognition = null;
            start.disabled = true;
            status.textContent = 'No se pudo preparar el reconocimiento. La consulta escrita está disponible.';
        }
    }
    const synthesis = window.speechSynthesis;
    if (answer && speak && silence) {
        if (synthesis && window.SpeechSynthesisUtterance) {
            speak.hidden = false;
            silence.hidden = false;
            speak.disabled = !read.checked;
            read.addEventListener('change', () => {
                speak.disabled = !read.checked;
                if (!read.checked) {
                    cancelSpeech();
                    speechStatus.textContent = 'Lectura desactivada.';
                }
            });
            speak.addEventListener('click', () => {
                if (!read.checked) return;
                abortRecognition();
                cancelSpeech();
                const utterance = new window.SpeechSynthesisUtterance(answer.dataset.spoken);
                utterance.lang = 'es-BO';
                const voices = synthesis.getVoices();
                const voice = voices.find((v) => v.lang.toLowerCase() === 'es-bo') || voices.find((v) => v.lang.toLowerCase().startsWith('es'));
                if (voice) utterance.voice = voice;
                utterance.onend = () => { silence.disabled = true; speechStatus.textContent = 'Lectura finalizada.'; };
                utterance.onerror = () => { silence.disabled = true; speechStatus.textContent = 'El navegador no pudo leer el resumen. La respuesta visual sigue disponible.'; };
                silence.disabled = false;
                speechStatus.textContent = 'Leyendo solo el resumen de la respuesta.';
                synthesis.speak(utterance);
            });
            silence.addEventListener('click', () => { cancelSpeech(); speechStatus.textContent = 'Lectura detenida.'; });
        } else {
            read.disabled = true;
            speechStatus.textContent = 'Este navegador no ofrece síntesis de voz. La respuesta visual está disponible.';
        }
    } else if (!synthesis || !window.SpeechSynthesisUtterance) {
        read.disabled = true;
    }
    form.addEventListener('submit', () => { abortRecognition(); cancelSpeech(); });
    window.addEventListener('pagehide', () => { abortRecognition(); cancelSpeech(); });
})();
