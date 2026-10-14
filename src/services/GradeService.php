<?php
/**
 * Grade Calculation & Weighted Average Service
 * Handles weighted average mathematics, grade scale boundary checks, and projection analysis
 */

class GradeService {

    /**
     * Calculates weighted average and progress metrics for a student given evaluations and grades
     */
    public static function calculateStudentAverage(array $evaluations, array $grades, float $minPassingGrade = 3.0, float $maxGrade = 5.0): array {
        if (empty($evaluations)) {
            return [
                'weightedAverage'      => null,
                'accumulatedScore'     => 0.0,
                'totalEvaluatedWeight' => 0.0,
                'remainingWeight'      => 100.0,
                'projectedNeededGrade' => null,
                'isPassing'            => false,
                'status'               => 'NO_EVALUATIONS',
                'details'              => []
            ];
        }

        // Map grades by evaluation_id
        $gradesMap = [];
        foreach ($grades as $g) {
            $gradesMap[(int)$g['evaluation_id']] = $g;
        }

        $totalWeightedScore = 0.0;
        $totalEvaluatedWeight = 0.0;
        $currentContribution = 0.0;
        $details = [];

        foreach ($evaluations as $ev) {
            $evId = (int)$ev['id'];
            $evWeight = (float)$ev['weight'];
            $gradeObj = $gradesMap[$evId] ?? null;
            $score = ($gradeObj !== null && $gradeObj['score'] !== null) ? (float)$gradeObj['score'] : null;
            $isGraded = ($score !== null);

            if ($isGraded) {
                $totalWeightedScore += $score * ($evWeight / 100.0);
                $totalEvaluatedWeight += $evWeight;
                $currentContribution += $score * ($evWeight / 100.0);
            }

            $details[] = [
                'evaluationId' => $evId,
                'name'         => $ev['name'],
                'type'         => $ev['type'],
                'weight'       => $evWeight,
                'maxScore'     => (float)($ev['max_score'] ?? 5.0),
                'score'        => $score,
                'feedback'     => $gradeObj['feedback'] ?? null,
                'updatedAt'    => $gradeObj['updated_at'] ?? null,
                'isGraded'     => $isGraded
            ];
        }

        // Calculate weighted average of evaluated items
        $weightedAverage = null;
        if ($totalEvaluatedWeight > 0) {
            $weightedAverage = round($totalWeightedScore / ($totalEvaluatedWeight / 100.0), 2);
        }

        $remainingWeight = max(0.0, round(100.0 - $totalEvaluatedWeight, 2));

        // Projection calculation
        $projectedNeededGrade = null;
        if ($remainingWeight == 0) {
            $projectedNeededGrade = ($currentContribution >= $minPassingGrade) ? 'APROBADO' : 'REPROBADO';
        } else {
            $needed = ($minPassingGrade - $currentContribution) / ($remainingWeight / 100.0);
            if ($needed <= 0) {
                $projectedNeededGrade = 'APROBADO';
            } elseif ($needed > $maxGrade) {
                $projectedNeededGrade = 'NO_ALCANZABLE';
            } else {
                $projectedNeededGrade = round($needed, 2);
            }
        }

        $isPassing = ($weightedAverage !== null) ? ($weightedAverage >= $minPassingGrade) : false;

        return [
            'weightedAverage'      => $weightedAverage,
            'accumulatedScore'     => round($currentContribution, 2),
            'totalEvaluatedWeight' => round($totalEvaluatedWeight, 2),
            'remainingWeight'      => $remainingWeight,
            'projectedNeededGrade' => $projectedNeededGrade,
            'isPassing'            => $isPassing,
            'minPassingGrade'      => $minPassingGrade,
            'maxGrade'             => $maxGrade,
            'status'               => ($totalEvaluatedWeight >= 99.9) ? 'FINAL' : 'IN_PROGRESS',
            'details'              => $details
        ];
    }

    /**
     * Validates that adding a new evaluation weight does not exceed 100% total
     */
    public static function validateEvaluationWeights(array $existingEvaluations, float $newWeight, $excludeEvaluationId = null): array {
        $currentTotalWeight = 0.0;
        foreach ($existingEvaluations as $ev) {
            if ($excludeEvaluationId !== null && (int)$ev['id'] === (int)$excludeEvaluationId) {
                continue;
            }
            $currentTotalWeight += (float)$ev['weight'];
        }

        $total = $currentTotalWeight + $newWeight;
        if ($total > 100.001) {
            return [
                'valid'          => false,
                'currentTotal'   => round($currentTotalWeight, 2),
                'projectedTotal' => round($total, 2),
                'maxAllowed'     => max(0.0, round(100.0 - $currentTotalWeight, 2))
            ];
        }

        return [
            'valid'          => true,
            'currentTotal'   => round($currentTotalWeight, 2),
            'projectedTotal' => round($total, 2)
        ];
    }

    /**
     * Validates numeric score against institutional grading scale boundaries
     */
    public static function validateScoreBounds($score, float $minGrade = 0.0, float $maxGrade = 5.0): array {
        if (!is_numeric($score)) {
            return ['valid' => false, 'reason' => 'El valor de la nota debe ser numérico.'];
        }

        $num = (float)$score;
        if ($num < $minGrade || $num > $maxGrade) {
            return [
                'valid'  => false,
                'reason' => "La nota ({$num}) está fuera del rango permitido [{$minGrade} - {$maxGrade}]."
            ];
        }

        return ['valid' => true, 'score' => round($num, 2)];
    }
}
