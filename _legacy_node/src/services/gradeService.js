/**
 * Grade Calculation & Weighted Average Service
 * Handles weighted average mathematics, grade scale boundary checks, and projection analysis
 */

export function calculateStudentAverage(evaluations, grades, minPassingGrade = 3.0, maxGrade = 5.0) {
  if (!evaluations || evaluations.length === 0) {
    return {
      weightedAverage: null,
      accumulatedScore: 0,
      totalEvaluatedWeight: 0,
      remainingWeight: 100,
      projectedNeededGrade: null,
      isPassing: false,
      status: 'NO_EVALUATIONS',
      details: []
    };
  }

  // Create lookup map of grades by evaluation_id
  const gradesMap = new Map();
  if (grades && Array.isArray(grades)) {
    grades.forEach(g => {
      gradesMap.set(g.evaluation_id, g);
    });
  }

  let totalWeightedScore = 0;
  let totalEvaluatedWeight = 0;
  let currentContribution = 0; // Contribution out of 5.0 (or 100%) based on total course scale

  const details = evaluations.map(ev => {
    const gradeObj = gradesMap.get(ev.id);
    const score = gradeObj != null ? Number(gradeObj.score) : null;
    const isGraded = score != null;

    if (isGraded) {
      totalWeightedScore += score * (ev.weight / 100);
      totalEvaluatedWeight += ev.weight;
      currentContribution += score * (ev.weight / 100);
    }

    return {
      evaluationId: ev.id,
      name: ev.name,
      type: ev.type,
      weight: ev.weight,
      maxScore: ev.max_score,
      score: score,
      feedback: gradeObj ? gradeObj.feedback : null,
      updatedAt: gradeObj ? gradeObj.updated_at : null,
      isGraded
    };
  });

  // Calculate weighted average relative to graded items
  let weightedAverage = null;
  if (totalEvaluatedWeight > 0) {
    // Standard normalized weighted average of evaluated items
    weightedAverage = Number(( (totalWeightedScore / (totalEvaluatedWeight / 100)) ).toFixed(2));
  }

  const remainingWeight = Math.max(0, Number((100 - totalEvaluatedWeight).toFixed(2)));

  // Calculate projected grade needed on remaining weight to achieve minPassingGrade
  let projectedNeededGrade = null;
  if (remainingWeight === 0) {
    projectedNeededGrade = currentContribution >= minPassingGrade ? 'APROBADO' : 'REPROBADO';
  } else {
    const needed = (minPassingGrade - currentContribution) / (remainingWeight / 100);
    if (needed <= 0) {
      projectedNeededGrade = 'APROBADO';
    } else if (needed > maxGrade) {
      projectedNeededGrade = 'NO_ALCANZABLE';
    } else {
      projectedNeededGrade = Number(needed.toFixed(2));
    }
  }

  const isPassing = weightedAverage != null ? weightedAverage >= minPassingGrade : false;

  return {
    weightedAverage,
    accumulatedScore: Number(currentContribution.toFixed(2)),
    totalEvaluatedWeight: Number(totalEvaluatedWeight.toFixed(2)),
    remainingWeight,
    projectedNeededGrade,
    isPassing,
    minPassingGrade,
    maxGrade,
    status: totalEvaluatedWeight === 100 ? 'FINAL' : 'IN_PROGRESS',
    details
  };
}

/**
 * Validates that an evaluation weight is valid and doesn't exceed 100% total
 */
export function validateEvaluationWeights(existingEvaluations, newWeight, excludeEvaluationId = null) {
  let currentTotalWeight = 0;
  for (const ev of existingEvaluations) {
    if (excludeEvaluationId && ev.id === Number(excludeEvaluationId)) continue;
    currentTotalWeight += Number(ev.weight);
  }

  const total = currentTotalWeight + Number(newWeight);
  if (total > 100.001) {
    return {
      valid: false,
      currentTotal: currentTotalWeight,
      projectedTotal: total,
      maxAllowed: Math.max(0, Number((100 - currentTotalWeight).toFixed(2)))
    };
  }

  return { valid: true, currentTotal: currentTotalWeight, projectedTotal: total };
}

/**
 * Validates that a numeric score is within the institutional grading scale bounds
 */
export function validateScoreBounds(score, minGrade = 0.0, maxGrade = 5.0) {
  const num = Number(score);
  if (isNaN(num)) {
    return { valid: false, reason: 'El valor de la nota debe ser numérico.' };
  }
  if (num < minGrade || num > maxGrade) {
    return {
      valid: false,
      reason: `La nota (${num}) está fuera del rango permitido [${minGrade} - ${maxGrade}].`
    };
  }
  return { valid: true, score: Number(num.toFixed(2)) };
}
