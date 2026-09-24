<?php
/**
 * AI transcription / summarization integration point.
 *
 * STUBBED: no speech-to-text or summarization service is connected yet.
 * Both functions below return clearly-labeled placeholder text so the
 * rest of the pipeline (record -> upload -> store -> versioned review)
 * can be built, tested, and demoed end-to-end today without depending on
 * a paid API or a locally-hosted model. Replace the body of each function
 * with a real call when that decision is made; nothing else in the app
 * needs to change, since every caller only depends on these signatures.
 */

/** Transcribes the audio file at $audioFilePath into text. */
function transcribeAudio(string $audioFilePath): string
{
    // TODO: send the file at $audioFilePath to a real speech-to-text
    // engine (e.g. OpenAI's /v1/audio/transcriptions endpoint, model
    // "whisper-1" or "gpt-4o-transcribe") and return the transcript text
    // it responds with.
    return "[PLACEHOLDER TRANSCRIPT -- no speech-to-text service is connected yet. "
         . "This stand-in text lets the review workflow be tested; it is not "
         . "generated from the actual recording (" . basename($audioFilePath) . ").]";
}

/** Produces a short, plain-language summary of a consultation transcript. */
function summarizeTranscript(string $transcriptText): string
{
    // TODO: send $transcriptText to a real language model (e.g. an OpenAI
    // or Anthropic chat completion) asking it to summarize the visit in
    // plain language for the patient, and return that summary.
    return "[PLACEHOLDER SUMMARY -- no summarization service is connected yet. "
         . "This stand-in text lets the review workflow be tested; it is not "
         . "generated from the actual transcript.]";
}
