import isEmpty from 'lodash/isEmpty'

// TODO : remove the use of navigate()

import {makeActionCreator} from '#/main/core/scaffolding/actions'
import {API_REQUEST} from '#/main/core/api/actions'
import {actions as resourceActions} from '#/main/core/resource/actions'

import quizSelectors from './../selectors'
import {select as playerSelectors} from './selectors'
import {generatePaper} from './../papers/generator'
import {normalize, denormalizeAnswers, denormalize} from './normalizer'
import moment from 'moment'
import {actions as paperAction} from '../papers/actions'

export const ATTEMPT_START  = 'ATTEMPT_START'
export const ATTEMPT_FINISH = 'ATTEMPT_FINISH'
export const STEP_OPEN      = 'STEP_OPEN'
export const ANSWER_UPDATE  = 'ANSWER_UPDATE'
export const ANSWERS_SUBMIT = 'ANSWERS_SUBMIT'
export const TEST_MODE_SET  = 'TEST_MODE_SET'
export const STEP_FEEDBACK  = 'STEP_FEEDBACK'
export const HINT_USE       = 'HINT_USE'

export const actions = {}

actions.setTestMode = makeActionCreator(TEST_MODE_SET, 'testMode')
actions.startAttempt = makeActionCreator(ATTEMPT_START, 'paper', 'answers')
actions.finishAttempt = makeActionCreator(ATTEMPT_FINISH, 'paper', 'answers')
actions.openStep = makeActionCreator(STEP_OPEN, 'step')
actions.updateAnswer = makeActionCreator(ANSWER_UPDATE, 'questionId', 'answerData')
actions.submitAnswers = makeActionCreator(ANSWERS_SUBMIT, 'quizId', 'paperId', 'answers')
actions.stepFeedback = makeActionCreator(STEP_FEEDBACK)

actions.useHint = makeActionCreator(HINT_USE, 'questionId', 'hint')

actions.fetchAttempt = quizId => ({
  [API_REQUEST]: {
    url: ['exercise_attempt_start', {exerciseId: quizId}],
    request: {method: 'POST'},
    success: (data, dispatch) => {
      const normalized = normalize(data)
      dispatch(actions.initPlayer(normalized.paper, normalized.answers))
    }
  }
})

actions.sendAnswers = (quizId, paperId, answers) => ({
  [API_REQUEST]: {
    url: ['exercise_attempt_submit', {exerciseId: quizId, id: paperId}],
    request: {
      method: 'PUT',
      body: JSON.stringify(denormalizeAnswers(answers))
    },
    success: (data, dispatch) =>
      dispatch(actions.submitAnswers(quizId, paperId, answers))
  }
})

actions.requestHint = (quizId, paperId, questionId, hintId) => ({
  [API_REQUEST]: {
    url: ['exercise_attempt_hint_show', {exerciseId: quizId, id: paperId, questionId: questionId, hintId: hintId}],
    success: (hint, dispatch) => dispatch(actions.useHint(questionId, hint))
  }
})

actions.requestEnd = (quizId, paperId, navigate) => ({
  [API_REQUEST]: {
    url: ['exercise_attempt_finish', {exerciseId: quizId, id: paperId}],
    request: {
      method: 'PUT'
    },
    success: (data, dispatch) => {
      const normalized = normalize(data)
      dispatch(actions.handleAttemptEnd(normalized.paper, navigate))
    }
  }
})

// previous paper seems to never be passed here.
actions.play = (previousPaper = null) => {
  return (dispatch, getState) => {
    dispatch(resourceActions.triggerLifecycleAction('play'))

    if (!playerSelectors.offline(getState())) {
      // Normal : Request a paper from the API and open the player
      return dispatch(actions.fetchAttempt(quizSelectors.quiz(getState()).id))
    } else {
      // Offline & Tests : create a new local paper and open the player
      // Promise is to expose the same interface than when there are async calls
      return Promise.resolve(dispatch(
        actions.initPlayer(generatePaper(
          quizSelectors.quiz(getState()),
          quizSelectors.steps(getState()),
          quizSelectors.items(getState()),
          previousPaper
        ))
      ))
    }
  }
}

actions.submit = (quizId, paperId, answers = {}) => {
  return (dispatch, getState) => {
    if (!isEmpty(answers)) {
      const updated = {}
      for (let answer in answers) {
        if (answers.hasOwnProperty(answer) && answers[answer]._touched) {
          // Answer has been modified => send it to the server
          updated[answer] = answers[answer]
        }
      }

      if (!isEmpty(updated)) {
        if (!playerSelectors.offline(getState())) {
          return dispatch(actions.sendAnswers(quizId, paperId, updated))
        } else {
          // This seems a little hacky but if we dispatch a regular action
          // we don't have access to the promise interface provided by redux-thunk
          // and we can not link next actions using `then` callback like when the server is called.
          // Offline mode should be bundled in the api middleware to avoid this promise wrapping
          return dispatch((dispatch) => Promise.resolve(dispatch(actions.submitAnswers(quizId, paperId, updated))))
        }
      }

      // No update in answers
      return Promise.resolve()
    }

    // Nothing to do, we just resolve a promise to let the action chain continue
    return Promise.resolve()
  }
}

actions.navigateTo = (quizId, paperId, nextStep, pendingAnswers = {}, currentStepSend = true, openFeedback = false) => {
  return (dispatch) => {
    if (currentStepSend) {
      dispatch(actions.submit(quizId, paperId, pendingAnswers)).then(() =>
        openFeedback ? dispatch(actions.stepFeedback()): dispatch(actions.openStep(nextStep))
      )
    } else {
      openFeedback ? dispatch(actions.stepFeedback()): dispatch(actions.openStep(nextStep))
    }
  }
}

actions.finish = (quizId, paper, pendingAnswers = {}, showFeedback = false, navigate) => {
  return (dispatch, getState) => {
    dispatch(resourceActions.triggerLifecycleAction('end'))

    if (!showFeedback) {
      dispatch(actions.submit(quizId, paper.id, pendingAnswers)).then(() => {
        endQuiz(quizId, paper, navigate, dispatch, getState)
      })
    } else {
      endQuiz(quizId, paper, navigate, dispatch, getState)
    }
  }
}

actions.handleAttemptEnd = (paper, navigate) => {
  return (dispatch, getState) => {
    // Finish the current attempt
    dispatch(actions.finishAttempt(paper, playerSelectors.answers(getState())))
    dispatch(paperAction.addPaper(buildPaper(paper, playerSelectors.answers(getState()))))

    // We will decide here if we show the correction now or not and where we redirect the user
    if (playerSelectors.hasEndPage(getState())) {
      // Show the end page
      navigate('play/end')
    } else {
      switch (playerSelectors.showCorrectionAt(getState())) {
        case 'validation': {
          dispatch(paperAction.setCurrentPaper(paper.id))
          navigate('papers/' + paper.id)
          break
        }
        case 'date': {
          const correctionDate = moment(playerSelectors.correctionDate(getState()))
          const today = moment()
          const showPaper = today.diff(correctionDate, 'days') >= 0

          if (showPaper) {
            dispatch(paperAction.setCurrentPaper(paper.id))
            navigate('papers/' + paper.id)
          } else {
            navigate('overview')
          }

          break
        }
        default: navigate('overview')
      }
    }
  }
}

actions.initPlayer = (paper, answers = {}) => {
  return (dispatch) => {
    dispatch(actions.startAttempt(paper, answers))

    const firstStep = paper.structure.steps[0]

    dispatch(actions.openStep(firstStep))
  }
}

actions.showHint = (quizId, paperId, questionId, hint) => {
  return (dispatch, getState) => {
    if (!playerSelectors.offline(getState())) {
      return dispatch(actions.requestHint(quizId, paperId, questionId, hint.id))
    } else {
      return dispatch(actions.useHint(questionId, hint))
    }
  }
}

function endQuiz(quizId, paper, navigate, dispatch, getState) {
  //the current step was already done
  if (!playerSelectors.offline(getState())) {
    // Send finish request to API
    return dispatch(actions.requestEnd(quizId, paper.id, navigate))
  } else {
    // Finish the attempt and use quiz config to know what to do next
    return dispatch(actions.handleAttemptEnd(paper, navigate))
  }
}

function buildPaper(paper, answers) {
  return denormalize(paper, answers)
}
