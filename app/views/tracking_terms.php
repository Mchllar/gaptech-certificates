<?php
/**
 * The back of the installation certificate: the guarantee terms.
 *
 * Goes in app/views/tracking_terms.php, and is included at the end of
 * tracking_sheet.php so the certificate prints as two pages - front, then this.
 *
 * Transcribed from the printed certificate (serial 01182). THE WORDING IS A
 * WARRANTY: it is what GAPTECH is bound by, so it has been copied exactly, down
 * to the spellings. Three of them look like mistakes in the original rather than
 * in the transcription:
 *
 *     "lighting"   almost certainly meant to be "lightning"   (clause 3e)
 *     "adaptions"  usually written "adaptations"              (clause 3f)
 *     "incured"    "incurred"                                 (clause 3g)
 *
 * They have NOT been corrected here. Changing the words on a warranty is a
 * decision for whoever owns it, not for whoever types it. If the managers want
 * them fixed, this one file is where.
 *
 * $tt below is just a local shorthand for the clause list; everything else about
 * the page is in the <style> block.
 */

$tt = [
    [
        '1.',
        'GAP TECH SOLUTIONS LTD warrants its products to be free from defects in '
        . 'parts and workmanship from a period of 12 months (the warranty period) '
        . 'from the invoice date in accordance with the conditions stated below.',
        [],
    ],
    [
        '2.',
        'This warranty includes:',
        [
            ['a.', 'Repair any defect caused during reasonable operation of the product '
                 . 'in accordance to GAP TECH SOLUTIONS LTD instructions.'],
            ['b.', 'Replacement of any required parts at no additional cost in order to '
                 . 'fulfill this warranty obligation. Replacement of any unit or part '
                 . 'thereof, is at the sole discretion of GAP TECH SOLUTIONS LTD. and '
                 . 'all replaced parts remain the property of GAP TECH SOLUTIONS LTD.'],
            ['c.', 'Transportation/shipment of the faulty unit to GAP TECH SOLUTIONS LTD '
                 . 'premises is at the sole responsibility of the distributor and will be '
                 . 'in accordance with GAP TECH SOLUTIONS LTD&rsquo;s RMA procedures.'],
        ],
    ],
    [
        '3.',
        'This warranty is void under any one of the following conditions, or part thereof:',
        [
            ['a.', 'Installations not performed by GAP TECH SOLUTIONS LTD or by personnel '
                 . 'properly qualified by GAP TECH SOLUTIONS LTD'],
            ['b.', 'Operation of the unit in contravention to the manufacturer&rsquo;s '
                 . 'instructions'],
            ['c.', 'Damage resulting from variations in the electricity power supply to the '
                 . 'product or to any accessories, as well as from any electrical work '
                 . 'performed in connection with product in contravention of relevant local '
                 . 'electrical codes and standards'],
            ['d.', 'Accessories damaged because of unreasonable use'],
            ['e.', 'Damage caused by accident, fire, flood, lighting, and adverse '
                 . 'environmental conditions as well as damage caused by irregular power '
                 . 'supply and transport'],
            ['f.', 'Repairs/service or adaptions made to the equipment, including moving the '
                 . 'product performed by other than GAP TECH SOLUTIONS LTD qualified '
                 . 'personnel, or without written agreement by GAP TECH SOLUTIONS LTD '
                 . 'authorized personnel, or as a result of connecting the product to any '
                 . 'kind of equipment without prior authorization from GAP TECH SOLUTIONS LTD'],
            ['g.', 'This warranty covers GAP TECH SOLUTIONS LTD products only and does not '
                 . 'include damages resulting from loss of database information, loss of '
                 . 'records, damages resulting from using the software, as well as any other '
                 . 'incidental or direct damages of any kind that may be incured by third '
                 . 'parties resulting from the use of GAP TECH SOLUTIONS LTD products.'],
        ],
    ],
];
?>
<style>
  /*
   * The back of the certificate. Plain white, no logo and no watermark - the
   * original has none, and anything added here would be the system inventing
   * something the printed document does not have.
   */
  .terms-sheet {
    page-break-before: always;
    break-before: page;
    position: relative;
    width: 210mm;
    height: 296mm;              /* a hair under A4 so it cannot push a blank third page */
    box-sizing: border-box;
    padding: 20mm 14mm 16mm;
    background: #fff;
    color: #1a1a1a;
    font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
    font-size: 10.8pt;
    line-height: 1.52;
    overflow: hidden;
  }

  .terms-sheet h1 {
    margin: 0 0 11mm;
    text-align: center;
    font-size: 12.4pt;
    font-weight: bold;
    letter-spacing: .4px;
  }

  /*
   * A numbered clause runs full width and its continuation returns to the left
   * margin - no hanging indent. That is how the printed page reads, and it is
   * the thing that makes a transcription look wrong when you get it backwards.
   */
  .terms-clause {
    margin: 0 0 5.5mm;
  }
  .terms-clause b { font-weight: bold; }

  /*
   * A lettered sub-clause DOES hang: the letter sits out to the left and the
   * continuation lines align under the first word, not under the letter.
   */
  .terms-sub {
    margin: 0 0 4.5mm;
    padding-left: 16mm;
    text-indent: -5mm;
  }
  .terms-sub b { font-weight: bold; }

  @media print {
    .terms-sheet { height: auto; min-height: 0; }
  }
</style>

<div class="terms-sheet">
  <h1>GUARANTEE TERMS AND CONDITIONS</h1>

  <?php foreach ($tt as [$n, $lead, $subs]): ?>
    <p class="terms-clause"><b><?= $n ?></b> <?= $lead ?></p>
    <?php foreach ($subs as [$letter, $text]): ?>
      <p class="terms-sub"><b><?= $letter ?></b> <?= $text ?></p>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
