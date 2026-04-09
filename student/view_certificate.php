<?php
session_start();
require "dbconnect.php";

if (!isset($_GET['code'])) {
    die("Certificate code required.");
}

$code = $conn->real_escape_string($_GET['code']);

// Fetch certificate and student details
$sql = "
    SELECT sc.*, s.firstname, s.lastname, s.mi, d.department_name, s.year_level, s.section
    FROM student_certificates sc
    JOIN students s ON sc.student_id = s.stud_id
    JOIN departments d ON s.department_id = d.department_id
    WHERE sc.certificate_code = '$code'
";

$result = $conn->query($sql);
if ($result->num_rows == 0) {
    die("Invalid certificate code.");
}

$cert = $result->fetch_assoc();
$fullname = strtoupper($cert['firstname'] . ' ' . ($cert['mi'] ? $cert['mi'][0].'.' : '') . ' ' . $cert['lastname']);
$date = date('F d, Y', strtotime($cert['created_at']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Completion - <?php echo $fullname; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=Great+Vibes&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        @page {
            size: landscape;
            margin: 0;
        }
        body {
            background: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Roboto', sans-serif;
            print-color-adjust: exact;
        }
        .certificate-container {
            background: white;
            width: 100%; /* Flexible for print */
            max-width: 11in;
            aspect-ratio: 11 / 8.5; /* Maintain Letter aspect ratio */
            padding: 40px;
            position: relative;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            text-align: center;
            border: 20px solid #1a4f7a;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-sizing: border-box;
        }
        .inner-border {
            border: 2px solid #daa520;
            position: absolute;
            top: 15px;
            left: 15px;
            right: 15px;
            bottom: 15px;
            pointer-events: none;
        }
        h1 {
            font-family: 'Cinzel', serif;
            font-size: 48px;
            color: #1a4f7a;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        .subtitle {
            font-size: 18px;
            color: #555;
            margin-bottom: 30px;
        }
        .presented-to {
            font-size: 16px;
            margin-bottom: 10px;
            font-style: italic;
        }
        .student-name {
            font-family: 'Garand', serif;
            font-size: 56px;
            color: #daa520;
            margin: 5px 0 25px 0;
            border-bottom: 1px solid #ddd;
            display: inline-block;
            padding: 0 40px;
            min-width: 400px;
        }
        .description {
            font-size: 16px;
            line-height: 1.5;
            margin-bottom: 30px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        .date {
            font-size: 14px;
            margin-bottom: 40px;
        }
        .signatures {
            display: flex;
            justify-content: space-around;
            margin-top: 20px;
            width: 80%;
            margin-left: auto;
            margin-right: auto;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            padding-top: 5px;
            font-size: 14px;
        }
        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #1a4f7a;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        .print-btn:hover {
            background: #123755;
        }
        @media print {
            body { 
                background: white; 
                display: flex;
                justify-content: center;
                align-items: center;
                width: 100vw;
                height: 100vh;
                margin: 0;
                padding: 0;
                overflow: hidden;
            }
            .certificate-container { 
                box-shadow: none; 
                width: 100%; 
                height: 100%; 
                border: 20px solid #1a4f7a; /* Ensure border prints */
                page-break-inside: avoid;
                margin: 0;
                position: absolute;
                top: 0;
                left: 0;
            }
            .print-btn { display: none; }
        }
    </style>
</head>
<body>

    <div class="certificate-container">
        <div class="inner-border"></div>
        
        <h1>Certificate of Completion</h1>
        <p class="subtitle">Return of Service System (RSS)</p>
        
        <p class="presented-to">This certificate is proudly presented to</p>
        
        <div class="student-name"><?php echo $fullname; ?></div>
        
        <p class="description">
            For successfully completing the required <strong>320 hours</strong> of community service 
            under the Return of Service System (RSS) program of the<br>
            <strong><?php echo htmlspecialchars($cert['department_name'] ?? 'College of Technology'); ?></strong>.
        </p>
        
        <p class="date">Given this day, <strong><?php echo $date; ?></strong>.</p>
        
        <div class="signatures">
            <div class="signature-block">
                <div class="signature-line">
                    <strong>Coordinator</strong><br>
                    RSS Coordinator
                </div>
            </div>
            <div class="signature-block">
                <div class="signature-line">
                    <strong>Dean</strong><br>
                    <?php echo htmlspecialchars($cert['department_name'] ?? 'College of Technology'); ?>
                </div>
            </div>
        </div>
    </div>

    <button class="print-btn" onclick="window.print()">Print / Save as PDF</button>

</body>
</html>