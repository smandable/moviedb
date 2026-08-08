#!/usr/bin/env python3
"""Stop-hook reply gate: block the coordinator's turn once if its final message
carries claims from the binding one-call classes without their required markers.

Procedure, not reminder: the block reason returns to the model (never to the
user), naming each suspect sentence; the model revises and the next stop passes
(stop_hook_active guards against loops). Defensive throughout: any failure to
parse means exit 0 silently -- a broken gate must never wedge a session.

Checks (deliberately narrow -- the measured recurring classes only):
  1. Ownership/provenance stated bare: "PID N is/belongs to ...", "worktree X is
     <someone>'s", "launched by" -- without verify/verified/traced in-sentence.
  2. Counts without windows: "N consecutive", "all N runs/files/tests" without
     sampled/window/"of the last" in-sentence.
  3. The word VERIFIED in a sentence with no backticked command/artifact and no
     "by <lane/reviewer/me>" attribution.
  4. A quantitative perf reading (N ms / N fps) in a sentence naming no
     measurement class: none of Release, UNMEASURED, directional, smoke,
     "not a measurement".
  5. A live-state claim (reproduces / round-trips / matches / survives, about
     runtime / live / production / end-to-end / "after the reload") naming neither
     the ORACLE it read (DOM / API / state dump / log line) nor the SETTLE
     discipline (settled / polled / stabilised / converged), and not admitting the
     gap (unverified / transient). See the inline comment for the measured failure.
"""
import json
import re
import sys


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except Exception:
        return 0
    if payload.get("stop_hook_active"):
        return 0
    path = payload.get("transcript_path")
    if not path:
        return 0
    last_text = ""
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            for line in fh:
                try:
                    entry = json.loads(line)
                except Exception:
                    continue
                if entry.get("type") != "assistant":
                    continue
                msg = entry.get("message") or {}
                parts = [c.get("text", "") for c in msg.get("content", [])
                         if isinstance(c, dict) and c.get("type") == "text"]
                if parts:
                    last_text = "\n".join(parts)
    except Exception:
        return 0
    if not last_text:
        return 0

    flags = []
    sentences = re.split(r"(?<=[.!?])\s+|\n+", last_text)
    for s in sentences:
        low = s.lower()
        if re.search(r"\b(pid \d+|port \d+|that (process|session|worktree)|this worktree)\b.{0,40}\b(is|belongs to|owned by|launched by)\b", low) \
                and not re.search(r"\b(verif|traced|checked|confirmed by|parent chain|unverified|reportedly|lane reports)", low):
            flags.append(("ownership/provenance stated bare", s))
        if re.search(r"\b\d+ consecutive\b|\ball \d{2,}\b.{0,20}\b(runs|files|tests|frames)\b", low) \
                and not re.search(r"\b(sampled|window|of the last|per the)\b", low):
            flags.append(("count without its window", s))
        # Flag only ASSERTION shapes ("is VERIFIED", "Tier: VERIFIED", "VERIFIED —"),
        # not mentions of the word (describing the check, quoting a category, "an
        # attributed VERIFIED"). First live firing produced two mention-class false
        # positives; those sentences are now regression cases in reply_gate_battery.py.
        if re.search(r"\b(is|was|are|were|tier:?|:|—)\s*VERIFIED\b|\bVERIFIED\s*(—|:)", s) \
                and "`" not in s \
                and not re.search(r"\bverified by (the |a |an )?\w+|\bby me\b|\bunverified\b", low):
            flags.append(("VERIFIED asserted with no command/attribution in sentence", s))
        # A perf reading names its measurement class in the same sentence:
        # only Release on a quiet machine measures; anything else says so.
        if re.search(r"\d+(\.\d+)?\s*(ms|fps)\b", low) \
                and not re.search(r"\b(release|unmeasured|directional|smoke|not a measurement)\b", low):
            flags.append(("perf number without measurement class", s))
        # A LIVE-STATE claim names the surface it read, or that the state had settled.
        # Measured failure (2026-07-31, a Blazor/canvas app): a scenario was reported as reproducing
        # the authored world at runtime -- "23/23, zero warnings" -- twice, over a board that was
        # wrong. Two independent measurement errors, each sufficient alone: the check read the
        # CLIENT's own model, which the host carries over from the authoring mode, so it re-read the
        # authored state and called it runtime; and it read while a "starting engine" overlay was
        # still up, catching a stable-looking transient. A screenshot caught it, not the suite.
        # The shared shape: asserting a runtime outcome while naming neither the surface read nor
        # the fact that the state had stopped changing. Classes 1-4 do not cover it -- none of them
        # is about WHICH OBJECT was measured.
        if re.search(r"\b(reproduce[sd]?|round-?trips?|matches|survives?)\b", low) \
                and re.search(r"\b(runtime|live|in production|end-to-end|"
                              r"after the (restart|reload|switch|deploy))\b", low) \
                and not re.search(r"\b(settled|stabilis|stabiliz|polled|converged|"
                                  r"dom|api|state dump|log line|screenshot|"
                                  r"unverified|transient|read from)\b", low):
            flags.append(("live-state claim naming neither its oracle nor a settle", s))

    if not flags:
        return 0
    items = "; ".join(f"[{k}] \"{t.strip()[:140]}\"" for k, t in flags[:4])
    print(json.dumps({
        "decision": "block",
        "reason": ("Reply gate (fires once, then passes): before this reaches the user, either run the "
                   "one-command check and cite it, add the tier/source, or state the window. Suspect: "
                   + items),
    }))
    return 0


if __name__ == "__main__":
    sys.exit(main())
