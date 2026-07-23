"""Regression tests for the scheduled published-image rescan."""

from pathlib import Path


WORKFLOW = (
    Path(__file__).resolve().parents[1]
    / ".github"
    / "workflows"
    / "rescan-published-images.yml"
).read_text()


def test_rescan_counts_distinct_cves():
    assert (
        "COUNT=$(jq '[.Results[]?.Vulnerabilities[]?.VulnerabilityID] "
        '| unique | length\' "$RESULT")'
    ) in WORKFLOW
    assert (
        'TOTAL=$(cut -f4 "$FINDINGS" | sort -u | sed \'/^$/d\' '
        "| wc -l | tr -d ' ')"
    ) in WORKFLOW
    assert "TOTAL=$((TOTAL + COUNT))" not in WORKFLOW


def test_rescan_report_labels_counts_as_cves():
    assert (
        "Trivy found **$TOTAL** distinct fixable HIGH/CRITICAL CVEs "
        "across the published images."
    ) in WORKFLOW
    assert "| Tag | Platform | CVEs |" in WORKFLOW
